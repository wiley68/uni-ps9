<?php

declare(strict_types=1);

/**
 * AUD-021: runtime mTLS private-key passphrase provider.
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

$env = MtlsPrivateKeyPassphraseProvider::ENV_VAR;
assertAud021Provider($env === 'UNIPAYMENT_MTLS_KEY_PASSPHRASE', 'A: env var name');

// A: present
$provider = new MtlsPrivateKeyPassphraseProvider(static function (): ?string {
    return "  test-mtls-passphrase\n";
});
assertAud021Provider($provider->isConfigured(), 'A: configured');
assertAud021Provider($provider->resolve() === 'test-mtls-passphrase', 'A: trim + return');
assertAud021Provider($provider->require() === 'test-mtls-passphrase', 'A: require');

// B: absent
$missing = new MtlsPrivateKeyPassphraseProvider(static function (): ?string {
    return null;
});
assertAud021Provider(!$missing->isConfigured(), 'B: not configured');
assertAud021Provider($missing->resolve() === null, 'B: resolve null');
$threw = false;
try {
    $missing->require();
} catch (MtlsPrivateKeyPassphraseNotConfiguredException $exception) {
    $threw = true;
    $msg = $exception->getMessage();
    assertAud021Provider(strpos($msg, 'not configured') !== false, 'B/G: safe message');
    assertAud021Provider(strpos($msg, $env) !== false, 'B: names env var');
    assertAud021Provider(strpos($msg, 'test-mtls-passphrase') === false, 'G: no secret in message');
    assertAud021Provider(strpos($msg, '1234') === false, 'F/G: no historical passphrase in message');
}
assertAud021Provider($threw, 'B: fail closed typed exception');

// empty / whitespace-only → absent
$blank = new MtlsPrivateKeyPassphraseProvider(static function (): ?string {
    return "  \n";
});
assertAud021Provider($blank->resolve() === null, 'empty passphrase treated as missing');

fwrite(STDOUT, "OK (AUD-021 mTLS passphrase provider)\n");
