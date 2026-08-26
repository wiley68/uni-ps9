<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf\Certificate;

/**
 * Filesystem store for authoritative SmartUCF client certificate pair.
 */
final class CertificateLocalStore
{
    public const CERT_FILENAME = 'avalon_cert.pem';
    public const KEY_FILENAME = 'avalon_private_key.pem';
    public const LOCK_FILENAME = '.sync.lock';
    public const STATE_FILENAME = '.ssl_state.json';

    private const LOCK_TIMEOUT_SECONDS = 15;

    /** @var string */
    private $keysDir;
    /** @var CertificatePairValidator */
    private $validator;

    public function __construct(?string $keysDir = null, ?CertificatePairValidator $validator = null)
    {
        $this->keysDir = $keysDir ?? dirname(__DIR__, 3) . '/keys';
        $this->validator = $validator ?? new CertificatePairValidator();
    }

    public function keysDirectory(): string
    {
        return $this->keysDir;
    }

    public function certificatePath(): string
    {
        return $this->keysDir . '/' . self::CERT_FILENAME;
    }

    public function privateKeyPath(): string
    {
        return $this->keysDir . '/' . self::KEY_FILENAME;
    }

    public function ensureProtectionFiles(): void
    {
        if (!is_dir($this->keysDir)) {
            if (!@mkdir($this->keysDir, 0750, true) && !is_dir($this->keysDir)) {
                throw new CertificateSyncException(
                    'The certificate keys directory could not be created.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
        }

        $htaccess = $this->keysDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n"
            );
        }

        $index = $this->keysDir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
        }
    }

    /**
     * @return array{certificate_pem: string, private_key_pem: string}|null
     */
    public function readPairBytes(): ?array
    {
        $certPath = $this->certificatePath();
        $keyPath = $this->privateKeyPath();
        if (!is_file($certPath) || !is_file($keyPath) || !is_readable($certPath) || !is_readable($keyPath)) {
            return null;
        }
        $cert = file_get_contents($certPath);
        $key = file_get_contents($keyPath);
        if (!is_string($cert) || !is_string($key) || $cert === '' || $key === '') {
            return null;
        }

        return [
            'certificate_pem' => $cert,
            'private_key_pem' => $key,
        ];
    }

    /**
     * @return array{certificate_sha256: string, private_key_sha256: string, not_after: string}|null
     */
    public function validateLocalPair(): ?array
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            return null;
        }
        try {
            $validated = $this->validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
        } catch (\Throwable $exception) {
            return null;
        }

        return [
            'certificate_sha256' => $this->validator->sha256($pair['certificate_pem']),
            'private_key_sha256' => $this->validator->sha256($pair['private_key_pem']),
            'not_after' => $validated['not_after'],
        ];
    }

    /**
     * @param array{ssl_revision?: string, certificate_sha256: string, private_key_sha256: string} $meta
     */
    public function replacePair(string $certificatePem, string $privateKeyPem, array $meta): void
    {
        $this->ensureProtectionFiles();
        $validated = $this->validator->validate($certificatePem, $privateKeyPem);

        $incomingDir = $this->keysDir . '/.incoming';
        if (!is_dir($incomingDir) && !@mkdir($incomingDir, 0750, true) && !is_dir($incomingDir)) {
            throw new CertificateSyncException(
                'Certificate staging directory could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        $stageCert = $incomingDir . '/' . self::CERT_FILENAME;
        $stageKey = $incomingDir . '/' . self::KEY_FILENAME;
        $backupCert = $incomingDir . '/backup_' . self::CERT_FILENAME;
        $backupKey = $incomingDir . '/backup_' . self::KEY_FILENAME;

        $hadPrevious = is_file($this->certificatePath()) && is_file($this->privateKeyPath());
        if ($hadPrevious) {
            if (!@copy($this->certificatePath(), $backupCert) || !@copy($this->privateKeyPath(), $backupKey)) {
                throw new CertificateSyncException(
                    'Existing certificate pair could not be backed up.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
        }

        try {
            if (
                @file_put_contents($stageCert, $validated['certificate_pem']) === false
                || @file_put_contents($stageKey, $validated['private_key_pem']) === false
            ) {
                throw new CertificateSyncException(
                    'Staged certificate files could not be written.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
            @chmod($stageCert, 0640);
            @chmod($stageKey, 0600);

            if (!@rename($stageCert, $this->certificatePath())) {
                throw new CertificateSyncException(
                    'Certificate file could not be promoted.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
            if (!@rename($stageKey, $this->privateKeyPath())) {
                if ($hadPrevious && is_file($backupCert)) {
                    @copy($backupCert, $this->certificatePath());
                }
                throw new CertificateSyncException(
                    'Private key file could not be promoted.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }

            @chmod($this->certificatePath(), 0640);
            @chmod($this->privateKeyPath(), 0600);

            $finalCert = file_get_contents($this->certificatePath());
            $finalKey = file_get_contents($this->privateKeyPath());
            if ($finalCert !== $validated['certificate_pem'] || $finalKey !== $validated['private_key_pem']) {
                throw new CertificateSyncException(
                    'Promoted certificate pair does not match the validated content.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }

            $this->writeState([
                'ssl_revision' => (string) ($meta['ssl_revision'] ?? ''),
                'certificate_sha256' => (string) $meta['certificate_sha256'],
                'private_key_sha256' => (string) $meta['private_key_sha256'],
                'synced_at' => gmdate('c'),
            ]);
        } catch (\Throwable $exception) {
            if ($hadPrevious && is_file($backupCert) && is_file($backupKey)) {
                @copy($backupCert, $this->certificatePath());
                @copy($backupKey, $this->privateKeyPath());
            }
            @unlink($stageCert);
            @unlink($stageKey);
            if ($exception instanceof CertificateSyncException) {
                throw $exception;
            }
            throw new CertificateSyncException(
                'Certificate pair replacement failed: ' . $exception->getMessage(),
                CertificateSyncException::REASON_LOCAL_FS
            );
        } finally {
            @unlink($backupCert);
            @unlink($backupKey);
            @unlink($stageCert);
            @unlink($stageKey);
        }
    }

    public function createConsumerPairLease(): CertificateConsumerLease
    {
        $pair = $this->readPairBytes();
        if ($pair === null) {
            throw new CertificateSyncException(
                'Local certificate pair is missing or unreadable.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }
        $this->validator->validate($pair['certificate_pem'], $pair['private_key_pem']);
        $this->hardenAuthoritativePermissions();

        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'unipayment-ssl-'
            . bin2hex(random_bytes(8));

        if (!@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new CertificateSyncException(
                'Certificate lease directory could not be created.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        $certPath = $directory . '/certificate.pem';
        $keyPath = $directory . '/private_key.pem';
        try {
            if (
                @file_put_contents($certPath, $pair['certificate_pem']) === false
                || @file_put_contents($keyPath, $pair['private_key_pem']) === false
            ) {
                throw new CertificateSyncException(
                    'Certificate lease files could not be written.',
                    CertificateSyncException::REASON_LOCAL_FS
                );
            }
            @chmod($certPath, 0600);
            @chmod($keyPath, 0600);
        } catch (\Throwable $exception) {
            @unlink($certPath);
            @unlink($keyPath);
            @rmdir($directory);
            if ($exception instanceof CertificateSyncException) {
                throw $exception;
            }
            throw new CertificateSyncException(
                'Certificate lease creation failed.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        return new CertificateConsumerLease(
            $directory,
            $certPath,
            $keyPath,
            CertificatePairValidator::PASSPHRASE
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withExclusiveLock(callable $callback)
    {
        return $this->withLock(LOCK_EX, $callback);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withSharedLock(callable $callback)
    {
        return $this->withLock(LOCK_SH, $callback);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withLock(int $mode, callable $callback)
    {
        $this->ensureProtectionFiles();
        $lockPath = $this->keysDir . '/' . self::LOCK_FILENAME;
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new CertificateSyncException(
                'Certificate sync lock could not be opened.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($handle, $mode | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(50000);
        }

        if (!$locked) {
            fclose($handle);
            throw new CertificateSyncException(
                'Certificate sync lock timed out.',
                CertificateSyncException::REASON_LOCAL_FS
            );
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Remove runtime certificate material and sync artifacts (AUD-006).
     * Preserves package protection files (.htaccess, index.php) when present.
     *
     * @param bool $restoreProtectionFiles When true (certificate sync context), ensure
     *                                     protection files exist after cleanup. For true
     *                                     module uninstall pass false — do not recreate files.
     */
    public function purgeRuntimeArtifacts(bool $restoreProtectionFiles = true): bool
    {
        $ok = true;
        $preserve = ['.htaccess' => true, 'index.php' => true];

        foreach (
            [
                $this->certificatePath(),
                $this->privateKeyPath(),
                $this->keysDir . '/' . self::LOCK_FILENAME,
                $this->keysDir . '/' . self::STATE_FILENAME,
            ] as $path
        ) {
            if (!is_file($path)) {
                continue;
            }
            if (!@unlink($path)) {
                $ok = false;
            }
        }

        $incoming = $this->keysDir . '/.incoming';
        if (is_dir($incoming) && !$this->removeDirectoryContents($incoming, true)) {
            $ok = false;
        }

        if (is_dir($this->keysDir)) {
            foreach (scandir($this->keysDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || isset($preserve[$entry])) {
                    continue;
                }
                $path = $this->keysDir . '/' . $entry;
                if (is_file($path) && !@unlink($path)) {
                    $ok = false;
                }
            }
        }

        $this->cleanupLeaseTempDirectories();
        if ($restoreProtectionFiles) {
            $this->ensureProtectionFiles();
        }

        return $ok;
    }

    private function cleanupLeaseTempDirectories(): void
    {
        $temp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        foreach (glob($temp . DIRECTORY_SEPARATOR . 'unipayment-ssl-*') ?: [] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    private function removeDirectoryContents(string $directory, bool $removeDirectory): bool
    {
        $ok = true;
        foreach (glob(rtrim($directory, '/\\') . '/*') ?: [] as $path) {
            if (is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }
        if ($removeDirectory && is_dir($directory) && !@rmdir($directory)) {
            // Directory may be non-empty after partial failure.
            $ok = false;
        }

        return $ok;
    }

    /** Soften world-readable keys when PHP user can chmod them. */
    private function hardenAuthoritativePermissions(): void
    {
        if (is_file($this->certificatePath())) {
            @chmod($this->certificatePath(), 0640);
        }
        if (is_file($this->privateKeyPath())) {
            @chmod($this->privateKeyPath(), 0600);
        }
        if (is_dir($this->keysDir)) {
            @chmod($this->keysDir, 0750);
        }
    }

    /** @param array<string, string> $state */
    private function writeState(array $state): void
    {
        $path = $this->keysDir . '/' . self::STATE_FILENAME;
        @file_put_contents(
            $path,
            (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        @chmod($path, 0640);
    }
}
