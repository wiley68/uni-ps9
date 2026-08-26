<?php

declare(strict_types=1);

/**
 * AUD-021: secrets/smartucf-key.php mTLS passphrase provider.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseNotConfiguredException;
use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseProvider;

function assertAud021Provider(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertAud021Provider(
    MtlsPrivateKeyPassphraseProvider::RELATIVE_PATH === 'secrets/smartucf-key.php',
    'relative path contract'
);
assertAud021Provider(
    MtlsPrivateKeyPassphraseProvider::ARRAY_KEY === 'passphrase',
    'array key contract'
);

$tmp = sys_get_temp_dir() . '/unipayment-aud021-secret-' . bin2hex(random_bytes(4));
assertAud021Provider(@mkdir($tmp, 0700), 'temp dir');

// A: valid file
$validPath = $tmp . '/smartucf-key.php';
file_put_contents($validPath, "<?php\nreturn ['passphrase' => \"  test-mtls-passphrase\\n\"];\n");
$provider = new MtlsPrivateKeyPassphraseProvider($validPath);
assertAud021Provider($provider->isConfigured(), 'A: configured');
assertAud021Provider($provider->resolve() === 'test-mtls-passphrase', 'A: trim + return');
assertAud021Provider($provider->require() === 'test-mtls-passphrase', 'A: require');

// B: missing file
$missing = new MtlsPrivateKeyPassphraseProvider($tmp . '/absent.php');
assertAud021Provider(!$missing->isConfigured(), 'B: missing file');
$threw = false;
try {
    $missing->require();
} catch (MtlsPrivateKeyPassphraseNotConfiguredException $exception) {
    $threw = true;
    $msg = $exception->getMessage();
    assertAud021Provider(strpos($msg, 'secrets/smartucf-key.php') !== false, 'B: names secret file');
    assertAud021Provider(strpos($msg, 'test-mtls-passphrase') === false, 'K: no secret in message');
    assertAud021Provider(strpos($msg, '1234') === false, 'F: no historical passphrase');
    assertAud021Provider(strpos($msg, 'UNIPAYMENT_MTLS') === false, 'G: no env var in message');
}
assertAud021Provider($threw, 'B: fail closed');

// C: invalid return type
$badType = $tmp . '/bad-type.php';
file_put_contents($badType, "<?php\nreturn 'nope';\n");
assertAud021Provider((new MtlsPrivateKeyPassphraseProvider($badType))->resolve() === null, 'C: invalid return type');

// D: missing passphrase key
$missingKey = $tmp . '/missing-key.php';
file_put_contents($missingKey, "<?php\nreturn ['other' => 'x'];\n");
assertAud021Provider((new MtlsPrivateKeyPassphraseProvider($missingKey))->resolve() === null, 'D: missing key');

// E: empty passphrase
$empty = $tmp . '/empty.php';
file_put_contents($empty, "<?php\nreturn ['passphrase' => \"  \\n\"];\n");
assertAud021Provider((new MtlsPrivateKeyPassphraseProvider($empty))->resolve() === null, 'E: empty passphrase');

// G: provider source has no getenv / ENV_VAR
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Security/MtlsPrivateKeyPassphraseProvider.php');
assertAud021Provider(strpos($src, 'getenv') === false, 'G: no getenv');
assertAud021Provider(strpos($src, '$_ENV') === false, 'G: no $_ENV');
assertAud021Provider(strpos($src, '$_SERVER') === false, 'G: no $_SERVER');
assertAud021Provider(strpos($src, 'UNIPAYMENT_MTLS_KEY_PASSPHRASE') === false, 'G: no env var name');

@unlink($validPath);
@unlink($badType);
@unlink($missingKey);
@unlink($empty);
@rmdir($tmp);

fwrite(STDOUT, "OK (AUD-021 mTLS passphrase provider)\n");
