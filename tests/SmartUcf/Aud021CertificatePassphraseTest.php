<?php

declare(strict_types=1);

/**
 * AUD-021: CertificatePairValidator uses secrets/smartucf-key.php passphrase (OpenSSL pair).
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
$secretOk = $tmp . '/smartucf-key.php';
$secretMissing = $tmp . '/absent-smartucf-key.php';
$secretWrong = $tmp . '/wrong-smartucf-key.php';
file_put_contents($passFile, $passphrase);
file_put_contents($secretOk, "<?php\nreturn ['passphrase' => '" . $passphrase . "'];\n");
file_put_contents($secretWrong, "<?php\nreturn ['passphrase' => 'wrong-test-passphrase'];\n");

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

$missing = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider($secretMissing));
$threw = false;
try {
    $missing->validate($certPem, $keyPem);
} catch (MtlsPrivateKeyPassphraseNotConfiguredException $exception) {
    $threw = true;
    assertAud021Cert(strpos($exception->getMessage(), $passphrase) === false, 'K: no passphrase in exception');
}
assertAud021Cert($threw, 'B: validator fail closed without secret file');

$validator = new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider($secretOk));
$result = $validator->validate($certPem, $keyPem);
assertAud021Cert($result['certificate_pem'] === $certPem, 'H: cert preserved');
assertAud021Cert($result['private_key_pem'] === $keyPem, 'H: key preserved');
assertAud021Cert($result['not_after_timestamp'] > time(), 'H: validity window');

$failed = false;
try {
    (new CertificatePairValidator(new MtlsPrivateKeyPassphraseProvider($secretWrong)))->validate($certPem, $keyPem);
} catch (InvalidArgumentException $exception) {
    $failed = strpos($exception->getMessage(), 'could not be parsed') !== false;
    assertAud021Cert(strpos($exception->getMessage(), '1234') === false, 'F: no historical in error');
    assertAud021Cert(strpos($exception->getMessage(), 'wrong-test-passphrase') === false, 'K: wrong secret not echoed');
}
assertAud021Cert($failed, 'F: wrong passphrase fails without historical fallback');

@unlink($keyPath);
@unlink($certPath);
@unlink($passFile);
@unlink($secretOk);
@unlink($secretWrong);
@rmdir($tmp);

fwrite(STDOUT, "OK (AUD-021 certificate validator passphrase)\n");
