<?php

declare(strict_types=1);

/**
 * AUD-021: CertificatePairValidator uses runtime passphrase (OpenSSL pair).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseNotConfiguredException;
use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseProvider;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificatePairValidator;

function assertAud021Cert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$passphrase = 'test-mtls-passphrase';
$tmp = sys_get_temp_dir() . '/unipayment-aud021-' . bin2hex(random_bytes(4));
assertAud021Cert(@mkdir($tmp, 0700), 'temp dir');
$keyPath = $tmp . '/key.pem';
$certPath = $tmp . '/cert.pem';
$passFile = $tmp . '/pass.txt';
file_put_contents($passFile, $passphrase);

$genKey = 'openssl genrsa -aes256 -passout file:' . escapeshellarg($passFile)
    . ' -out ' . escapeshellarg($keyPath) . ' 2048 2>/dev/null';
$genCert = 'openssl req -new -x509 -key ' . escapeshellarg($keyPath)
    . ' -passin file:' . escapeshellarg($passFile)
    . ' -out ' . escapeshellarg($certPath)
    . ' -days 1 -subj "/CN=unipayment-aud021" 2>/dev/null';

exec($genKey, $out1, $code1);
exec($genCert, $out2, $code2);
assertAud021Cert($code1 === 0 && is_file($keyPath), 'H: generate encrypted key');
assertAud021Cert($code2 === 0 && is_file($certPath), 'H: generate certificate');

$certPem = (string) file_get_contents($certPath);
$keyPem = (string) file_get_contents($keyPath);
assertAud021Cert(strpos($keyPem, 'ENCRYPTED') !== false || strpos($keyPem, 'Proc-Type: 4,ENCRYPTED') !== false
    || strpos($keyPem, 'BEGIN ENCRYPTED PRIVATE KEY') !== false
    || strpos($keyPem, 'BEGIN RSA PRIVATE KEY') !== false, 'H: key material present');

// B: missing passphrase fail closed before parse
$missing = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider(static function (): ?string {
    return null;
}));
$threw = false;
try {
    $missing->validate($certPem, $keyPem);
} catch (MtlsPrivateKeyPassphraseNotConfiguredException $exception) {
    $threw = true;
    assertAud021Cert(strpos($exception->getMessage(), $passphrase) === false, 'G: no passphrase in exception');
}
assertAud021Cert($threw, 'B: validator fail closed without runtime secret');

// H / D: correct runtime passphrase validates
$okProvider = new MtlsPrivateKeyPassphraseProvider(static function () use ($passphrase): ?string {
    return $passphrase;
});
$validator = new CertificatePairValidator($okProvider);
$result = $validator->validate($certPem, $keyPem);
assertAud021Cert($result['certificate_pem'] === $certPem, 'H: cert preserved');
assertAud021Cert($result['private_key_pem'] === $keyPem, 'H: key preserved');
assertAud021Cert($result['not_after_timestamp'] > time(), 'H: validity window');

// F: wrong passphrase does not fall back to historical secret
$wrong = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider(static function (): ?string {
    return 'wrong-test-passphrase';
}));
$failed = false;
try {
    $wrong->validate($certPem, $keyPem);
} catch (InvalidArgumentException $exception) {
    $failed = strpos($exception->getMessage(), 'could not be parsed') !== false;
    assertAud021Cert(strpos($exception->getMessage(), '1234') === false, 'F: no historical in error');
    assertAud021Cert(strpos($exception->getMessage(), 'wrong-test-passphrase') === false, 'G: wrong secret not echoed');
}
assertAud021Cert($failed, 'F: wrong passphrase fails without historical fallback');

@unlink($keyPath);
@unlink($certPath);
@unlink($passFile);
@rmdir($tmp);

fwrite(STDOUT, "OK (AUD-021 certificate validator passphrase)\n");
