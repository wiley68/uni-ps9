<?php

declare(strict_types=1);

/**
 * AUD-021 contracts: no committed mTLS passphrase; runtime provider wired.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Security\MtlsPrivateKeyPassphraseProvider;
use PrestaShop\Module\Unipayment\SmartUcf\Certificate\CertificatePairValidator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;

function assertAud021(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$validatorSrc = (string) file_get_contents($root . '/src/SmartUcf/Certificate/CertificatePairValidator.php');
$storeSrc = (string) file_get_contents($root . '/src/SmartUcf/Certificate/CertificateLocalStore.php');
$clientSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionClient.php');
$providerSrc = (string) file_get_contents($root . '/src/Security/MtlsPrivateKeyPassphraseProvider.php');

// C / F: historical literal and constant gone from production sources
assertAud021(strpos($validatorSrc, "PASSPHRASE = '1234'") === false, 'C: no PASSPHRASE = 1234');
assertAud021(strpos($validatorSrc, 'public const PASSPHRASE') === false, 'C: PASSPHRASE constant removed');
assertAud021(strpos($validatorSrc, "foreach ([self::PASSPHRASE, '']") === false, 'F: no historical+empty fallback loop');
assertAud021(strpos($clientSrc, 'CertificatePairValidator::PASSPHRASE') === false, 'C: client not using PASSPHRASE');
assertAud021(strpos($storeSrc, 'CertificatePairValidator::PASSPHRASE') === false, 'C: store not using PASSPHRASE');
assertAud021(strpos($providerSrc, "'1234'") === false && strpos($providerSrc, '"1234"') === false, 'C: provider has no historical literal');

// D: validator uses provider require()
assertAud021(strpos($validatorSrc, 'MtlsPrivateKeyPassphraseProvider') !== false, 'D: validator depends on provider');
assertAud021(strpos($validatorSrc, 'passphrases->require()') !== false, 'D: validator requires runtime passphrase');

// E: session client uses provider
assertAud021(strpos($clientSrc, 'MtlsPrivateKeyPassphraseProvider') !== false, 'E: session client has provider');
assertAud021(strpos($clientSrc, 'passphrases->require()') !== false, 'E: mTLS path requires runtime passphrase');
assertAud021(strpos($clientSrc, 'CURLOPT_SSLKEYPASSWD') !== false, 'E: CURLOPT_SSLKEYPASSWD still set');

$seen = [];
$provider = new MtlsPrivateKeyPassphraseProvider(static function () use (&$seen): ?string {
    $seen[] = 'read';

    return 'test-mtls-passphrase';
});
$validator = new CertificatePairValidator($provider);
assertAud021($validator->privateKeyPassphrase() === 'test-mtls-passphrase', 'D: validator returns provider value');
assertAud021($seen !== [], 'D: provider consulted');

$sessionSeen = [];
$sessionProvider = new MtlsPrivateKeyPassphraseProvider(static function () use (&$sessionSeen): ?string {
    $sessionSeen[] = 'read';

    return 'test-mtls-passphrase';
});
$client = new SmartUcfSessionClient(new SmartUcfPayloadBuilder(), null, null, $sessionProvider);
assertAud021($client instanceof SmartUcfSessionClient, 'E: client constructs with provider');

// G: safe exception never embeds passphrase
$exSrc = (string) file_get_contents($root . '/src/Security/MtlsPrivateKeyPassphraseNotConfiguredException.php');
assertAud021(strpos($exSrc, 'test-mtls-passphrase') === false, 'G: exception class has no test secret');
assertAud021(strpos($exSrc, "'1234'") === false, 'G: exception has no historical secret');

fwrite(STDOUT, "OK (AUD-021 mTLS passphrase contracts)\n");
