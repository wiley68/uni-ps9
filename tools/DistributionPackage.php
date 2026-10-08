<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Build;

use RuntimeException;
use ZipArchive;

/** Build tooling only; never loaded by the module or shipped in its ZIP. */
final class DistributionPackage
{
    private const MANIFEST = 'package-manifest.json';
    private const REQUIRED = [
        'unipayment.php', 'index.php', 'config.xml', 'logo.png', 'composer.json',
        'config/environment.php', 'config/services.yml', 'vendor/autoload.php',
        'vendor/composer/ClassLoader.php', 'vendor/composer/autoload_real.php',
        'vendor/composer/autoload_static.php', 'vendor/composer/autoload_psr4.php',
        'vendor/composer/autoload_classmap.php', 'vendor/composer/platform_check.php',
        'src/Controller/ModuleApiController.php', 'src/Configuration/ConfigurationRepository.php',
        'src/Security/ModuleRequestAuthenticator.php', 'src/Security/ModuleRequestSignatureVerifier.php',
        'src/Security/ModuleRequestSignatureProtocol.php', 'src/Security/BoundedRawBodyReader.php',
        'src/Security/ApiNonceRepository.php', 'src/Security/SystemClock.php', 'src/Security/ClockInterface.php',
        'controllers/front/shopcache.php', 'controllers/front/smartucfdebuglog.php',
        'controllers/front/orderbankstatus.php', 'keys/.htaccess', 'secrets/.htaccess',
    ];

    public function __construct(private string $sourceRoot)
    {
        $this->sourceRoot = rtrim($sourceRoot, '/');
    }

    /** Only audited runtime paths may enter the staging tree. */
    public static function isRuntimePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, '..')) {
            return false;
        }
        if (in_array($path, [
            'unipayment.php', 'index.php', 'logo.png', 'composer.json',
            'config/environment.php',
            'bin/index.php', 'bin/signed-module-request.php',
            'keys/index.php', 'keys/.htaccess', 'secrets/index.php', 'secrets/.htaccess', 'var/index.php',
        ], true)) {
            return true;
        }
        if (preg_match('/\Aconfig(?:_[a-z]+)?\.xml\z/', $path)) {
            return true;
        }
        if (preg_match('#(?:^|/)(?:\.|tests?(?:/|$)|cache(?:/|$)|uploads?(?:/|$))#i', $path)) {
            return false;
        }
        [$directory] = explode('/', $path, 2);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extensions = [
            'src' => ['php'], 'controllers' => ['php'], 'upgrade' => ['php'],
            'config' => ['php', 'yml', 'yaml', 'xml'],
            'views' => ['php', 'tpl', 'js', 'css', 'png', 'svg', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'txt'],
            'mails' => ['php', 'html', 'txt'], 'translations' => ['php', 'xlf', 'xliff', 'json'],
        ];

        return isset($extensions[$directory])
            && in_array($extension, $extensions[$directory], true)
            && !str_starts_with($path, 'config/environment')
            && !preg_match('/(?:\.local\.|\.bak\.|\.tmp\.)/i', basename($path))
            && ($directory !== 'config' || !preg_match('/(?:secret|credentials)/i', basename($path)));
    }

    /** Resolve one literal production declaration without executing module PHP. */
    public static function resolveVersion(string $php): string
    {
        $tokens = array_values(array_filter(token_get_all($php), static function (mixed $token): bool {
            return !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $versions = [];
        for ($index = 0; $index < count($tokens) - 5; ++$index) {
            if (($tokens[$index][1] ?? null) !== '$this'
                || ($tokens[$index + 1][0] ?? null) !== T_OBJECT_OPERATOR
                || ($tokens[$index + 2][1] ?? null) !== 'version'
                || $tokens[$index + 3] !== '='
            ) {
                continue;
            }
            $literal = $tokens[$index + 4];
            if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING || $tokens[$index + 5] !== ';') {
                throw new RuntimeException('Module version must be one unambiguous literal assignment.');
            }
            $versions[] = substr($literal[1], 1, -1);
        }
        if (count($versions) !== 1 || !preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?\z/', $versions[0])) {
            throw new RuntimeException('Module version is missing, ambiguous or invalid.');
        }

        return $versions[0];
    }

    public static function artifactName(string $version): string
    {
        return 'CC_PrestaShop_9.x_UNI_v.' . $version . '.zip';
    }

    /** @return array<string, string> archive relative path => source path */
    public function sourceFiles(): array
    {
        $files = [];
        foreach (explode("\0", $this->run(['git', 'ls-files', '--cached', '-z'], $this->sourceRoot)) as $path) {
            if (!self::isRuntimePath($path)) {
                continue;
            }
            $absolute = $this->sourceRoot . '/' . $path;
            if (is_link($absolute) || !is_file($absolute) || !is_readable($absolute)) {
                throw new RuntimeException('Runtime source is missing, unreadable or a symlink: ' . $path);
            }
            $files[$path] = $absolute;
        }
        ksort($files);

        return $files;
    }

    public function build(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required.');
        }
        $version = self::resolveVersion($this->read($this->sourceRoot . '/unipayment.php'));
        $sha = trim($this->run(['git', 'rev-parse', 'HEAD'], $this->sourceRoot));
        $epoch = getenv('SOURCE_DATE_EPOCH');
        $epoch = $epoch === false ? trim($this->run(['git', 'show', '-s', '--format=%ct', 'HEAD'], $this->sourceRoot)) : $epoch;
        if (!ctype_digit($epoch) || (int) $epoch < 315532800 || (int) $epoch > 4354819198) {
            throw new RuntimeException('SOURCE_DATE_EPOCH must be within the ZIP timestamp range (1980–2107).');
        }
        $dist = $this->sourceRoot . '/dist';
        if (is_link($dist)) {
            throw new RuntimeException('dist must not be a symlink.');
        }
        $this->makeDirectory($dist);
        $stage = $dist . '/.build-' . bin2hex(random_bytes(8));
        $module = $stage . '/unipayment';
        $this->makeDirectory($module);
        $zipPath = $dist . '/' . self::artifactName($version);
        $temporaryZip = $stage . '/' . basename($zipPath);
        try {
            foreach ($this->sourceFiles() as $relative => $source) {
                $this->makeDirectory(dirname($module . '/' . $relative));
                if (!copy($source, $module . '/' . $relative)) {
                    throw new RuntimeException('Cannot stage runtime file: ' . $relative);
                }
            }
            // Lockfile is an input to the build, not runtime package metadata.
            if (!copy($this->sourceRoot . '/composer.lock', $module . '/composer.lock')) {
                throw new RuntimeException('Composer lockfile could not be staged.');
            }
            $environment = getenv();
            foreach (array_keys($environment) as $key) {
                if ($key === 'COMPOSER' || str_starts_with($key, 'COMPOSER_')) {
                    unset($environment[$key]);
                }
            }
            $environment['COMPOSER_HOME'] = $stage . '/composer-home';
            $environment['COMPOSER_CACHE_DIR'] = $stage . '/composer-cache';
            $this->run([
                'composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
                '--no-interaction', '--no-plugins', '--no-scripts',
            ], $module, $environment);
            // Keep source metadata untouched; the shipped metadata exposes production autoload only.
            $composer = json_decode($this->read($module . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            unset($composer['autoload-dev'], $composer['require-dev'], $composer['scripts']);
            $this->write($module . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            unlink($module . '/composer.lock');
            $this->makeDirectory($module . '/vendor');
            if (!copy($this->sourceRoot . '/keys/.htaccess', $module . '/vendor/.htaccess')) {
                throw new RuntimeException('vendor protection file could not be staged.');
            }
            $files = $this->treeFiles($module);
            $hashes = [];
            foreach ($files as $relative => $absolute) {
                $hashes[$relative] = hash_file('sha256', $absolute);
            }
            $manifest = [
                'module' => 'unipayment', 'version' => $version, 'source_git_sha' => $sha,
                'source_dirty' => trim($this->run(['git', 'status', '--porcelain', '--untracked-files=no'], $this->sourceRoot)) !== '',
                'source_date_epoch' => (int) $epoch, 'files' => $hashes,
            ];
            $this->write($module . '/' . self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            $files = $this->treeFiles($module);
            $zip = new ZipArchive();
            if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ZIP.');
            }
            // ZIP stores local timestamps; UTC makes builds independent of the host time zone.
            $previousTimezone = date_default_timezone_get();
            date_default_timezone_set('UTC');
            try {
                foreach ($files as $relative => $absolute) {
                    $name = 'unipayment/' . $relative;
                    if (!$zip->addFile($absolute, $name)
                        || !$zip->setMtimeName($name, (int) $epoch)
                        || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)
                        || !$zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9)
                    ) {
                        throw new RuntimeException('ZIP entry could not be written: ' . $relative);
                    }
                }
                if (!$zip->close()) {
                    throw new RuntimeException('ZIP could not be finalized.');
                }
            } finally {
                date_default_timezone_set($previousTimezone);
            }
            $count = $this->verify($temporaryZip);
            if (is_link($zipPath) || !rename($temporaryZip, $zipPath)) {
                throw new RuntimeException('Verified ZIP could not be published to dist.');
            }
            fwrite(STDOUT, 'ZIP: ' . $zipPath . "\nFiles: $count\nSize: " . filesize($zipPath) . " bytes\nSource Git SHA: $sha\n");

            return $zipPath;
        } finally {
            $this->removeStage($stage);
        }
    }

    /** Verify content, version naming, checksums and parity with the current source. */
    public function verify(string $path): int
    {
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('ZIP is missing or invalid.');
        }
        try {
            $contents = [];
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || !str_starts_with($name, 'unipayment/')) {
                    throw new RuntimeException('ZIP top-level directory must be exactly unipayment/.');
                }
                $relative = substr($name, strlen('unipayment/'));
                if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || isset($contents[$relative])) {
                    throw new RuntimeException('ZIP has an unsafe or duplicate entry.');
                }
                $zip->getExternalAttributesIndex($index, $system, $attributes);
                if (($attributes >> 16 & 0170000) === 0120000) {
                    throw new RuntimeException('ZIP must not contain symlinks.');
                }
                if ($relative !== self::MANIFEST
                    && !self::isRuntimePath($relative) && !str_starts_with($relative, 'vendor/')
                ) {
                    throw new RuntimeException('Non-runtime content in ZIP: ' . $relative);
                }
                if (preg_match('#(?:^|/)(?:tests?|docs|ps92-installed|dist|cache|uploads?|credentials|secrets?\.php)(?:/|$)|\.(?:pem|key|log|env|bak|tmp)$#i', $relative)
                    || preg_match('#(?:^|/)\.(?!htaccess$)[^/]+#', $relative)
                ) {
                    throw new RuntimeException('Forbidden development/secret/runtime data in ZIP: ' . $relative);
                }
                $bytes = $zip->getFromIndex($index);
                if ($bytes === false) {
                    throw new RuntimeException('ZIP entry could not be read.');
                }
                $contents[$relative] = $bytes;
            }
            foreach (array_merge(self::REQUIRED, [self::MANIFEST]) as $required) {
                if (!isset($contents[$required])) {
                    throw new RuntimeException('Required runtime file absent: ' . $required);
                }
            }
            $version = self::resolveVersion($contents['unipayment.php']);
            if (basename($path) !== self::artifactName($version)) {
                throw new RuntimeException('ZIP filename version does not match packaged unipayment.php.');
            }
            foreach ($contents as $relative => $bytes) {
                if (preg_match('/\Aconfig(?:_[a-z]+)?\.xml\z/', $relative)) {
                    $xml = simplexml_load_string($bytes, \SimpleXMLElement::class, LIBXML_NONET);
                    if ($xml === false || (string) $xml->version !== $version) {
                        throw new RuntimeException('Packaged XML version does not match module version.');
                    }
                }
                if (str_starts_with($relative, 'vendor/composer/autoload_')) {
                    $normalized = str_replace('\\\\', '\\', $bytes);
                    if (str_contains($normalized, 'Unipayment\\Tests\\') || str_contains($normalized, '/tests/')) {
                        throw new RuntimeException('Development autoload reference in production vendor.');
                    }
                }
            }
            $composer = json_decode($contents['composer.json'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($composer['autoload-dev']) || isset($composer['require-dev']) || isset($composer['scripts'])) {
                throw new RuntimeException('Development Composer metadata in ZIP.');
            }
            $manifest = json_decode($contents[self::MANIFEST], true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['module'] ?? null) !== 'unipayment' || ($manifest['version'] ?? null) !== $version
                || !preg_match('/\A[0-9a-f]{40}\z/', (string) ($manifest['source_git_sha'] ?? ''))
                || !is_array($manifest['files'] ?? null)
            ) {
                throw new RuntimeException('Package manifest metadata is invalid.');
            }
            $expectedHashes = $manifest['files'];
            $actualHashes = [];
            foreach ($contents as $relative => $bytes) {
                if ($relative !== self::MANIFEST) {
                    $actualHashes[$relative] = hash('sha256', $bytes);
                }
            }
            ksort($expectedHashes);
            ksort($actualHashes);
            if ($expectedHashes !== $actualHashes) {
                throw new RuntimeException('Package manifest inventory/checksum mismatch.');
            }
            foreach ($this->sourceFiles() as $relative => $source) {
                if ($relative === 'composer.json') {
                    $expected = json_decode($this->read($source), true, 512, JSON_THROW_ON_ERROR);
                    unset($expected['autoload-dev'], $expected['require-dev'], $expected['scripts']);
                    if ($expected !== $composer) {
                        throw new RuntimeException('Production Composer metadata differs from source.');
                    }
                } elseif (!isset($contents[$relative]) || $contents[$relative] !== $this->read($source)) {
                    throw new RuntimeException('Runtime source parity mismatch: ' . $relative);
                }
            }

            return count($contents);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string> */
    private function treeFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('Staging tree must not contain symlinks.');
            }
            if ($entry->isFile()) {
                $files[substr($entry->getPathname(), strlen($root) + 1)] = $entry->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** @param list<string> $command @param array<string, string>|null $environment */
    private function run(array $command, string $cwd, ?array $environment = null): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start ' . $command[0] . '.');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($command[0] . ' failed: ' . trim((string) $errors));
        }

        return (string) $output;
    }

    private function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create build directory.');
        }
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Could not read required build input.');
        }

        return $bytes;
    }

    private function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write staging file.');
        }
    }

    /** Delete only the fresh, private build directory created by this invocation. */
    private function removeStage(string $stage): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($stage);
    }
}
