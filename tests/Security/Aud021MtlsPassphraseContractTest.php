<?php

declare(strict_types=1);

/**
 * AUD-021 contracts: ZIP secret file + CP environment centralization.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Configuration\ModuleDeploymentEnvironment;
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
$cpClientSrc = (string) file_get_contents($root . '/src/Api/ControlPanelClient.php');
$envSrc = (string) file_get_contents($root . '/src/Configuration/ModuleDeploymentEnvironment.php');
$envFile = (string) file_get_contents($root . '/config/environment.php');
$secretFile = (string) file_get_contents($root . '/secrets/smartucf-key.php');

assertAud021(is_file($root . '/secrets/smartucf-key.php'), 'secret file exists');
assertAud021(strpos($secretFile, "'passphrase'") !== false || strpos($secretFile, '"passphrase"') !== false, 'secret key passphrase');
assertAud021(strpos($secretFile, 'REPLACE_WITH_DEPLOYMENT_PASSPHRASE') !== false, 'repo uses synthetic placeholder');
assertAud021(strpos($secretFile, "'1234'") === false && strpos($secretFile, '"1234"') === false, 'F: no historical passphrase in template');

assertAud021(strpos($validatorSrc, 'public const PASSPHRASE') === false, 'F: PASSPHRASE constant removed');
assertAud021(strpos($clientSrc, 'CertificatePairValidator::PASSPHRASE') === false, 'F: client no PASSPHRASE');
assertAud021(strpos($storeSrc, 'CertificatePairValidator::PASSPHRASE') === false, 'I: store no PASSPHRASE');
assertAud021(strpos($providerSrc, 'UNIPAYMENT_MTLS_KEY_PASSPHRASE') === false, 'G: no env dependency');
assertAud021(strpos($providerSrc, 'getenv') === false, 'G: no getenv in provider');

assertAud021(strpos($validatorSrc, 'MtlsPrivateKeyPassphraseProvider') !== false, 'H: validator uses provider');
assertAud021(strpos($validatorSrc, 'passphrases->require()') !== false, 'H: validator require()');
assertAud021(strpos($storeSrc, 'privateKeyPassphrase()') !== false, 'I: store uses validator passphrase');
assertAud021(strpos($clientSrc, 'MtlsPrivateKeyPassphraseProvider') !== false, 'J: session client has provider');
assertAud021(strpos($clientSrc, 'passphrases->require()') !== false, 'J: session client require()');

$tmp = sys_get_temp_dir() . '/unipayment-aud021-c-' . bin2hex(random_bytes(4));
assertAud021(@mkdir($tmp, 0700), 'temp dir');
$keyFile = $tmp . '/smartucf-key.php';
file_put_contents($keyFile, "<?php\nreturn ['passphrase' => 'test-mtls-passphrase'];\n");
$provider = new MtlsPrivateKeyPassphraseProvider($keyFile);
$validator = new CertificatePairValidator($provider);
assertAud021($validator->privateKeyPassphrase() === 'test-mtls-passphrase', 'H: validator returns provider value');
$client = new SmartUcfSessionClient(new SmartUcfPayloadBuilder(), null, null, $provider);
assertAud021($client instanceof SmartUcfSessionClient, 'J: client constructs with provider');

$exSrc = (string) file_get_contents($root . '/src/Security/MtlsPrivateKeyPassphraseNotConfiguredException.php');
assertAud021(strpos($exSrc, 'test-mtls-passphrase') === false, 'K: exception has no test secret');
assertAud021(strpos($exSrc, "'1234'") === false, 'K: exception has no historical secret');
assertAud021(strpos($exSrc, 'secrets/smartucf-key.php') !== false || strpos($exSrc, 'RELATIVE_PATH') !== false, 'K: safe file hint');

// L / M: CP base URL from one deployment config
assertAud021(is_file($root . '/config/environment.php'), 'L: environment.php exists');
assertAud021(strpos($envFile, 'control_panel_url') !== false, 'L: control_panel_url key');
assertAud021(strpos($cpClientSrc, 'uni.avalonbg.com') === false, 'L: no CP host literal in ControlPanelClient');
assertAud021(strpos($cpClientSrc, 'DEFAULT_BASE_URL') === false, 'L: DEFAULT_BASE_URL removed');
assertAud021(strpos($cpClientSrc, 'ModuleDeploymentEnvironment') !== false, 'L: client uses deployment environment');
assertAud021(strpos($envSrc, 'config/environment.php') !== false, 'L: resolver path');

$envTmp = $tmp . '/environment.php';
file_put_contents($envTmp, "<?php\nreturn ['control_panel_url' => 'https://cp-switch.example'];\n");
$deployment = new ModuleDeploymentEnvironment($envTmp);
assertAud021($deployment->controlPanelUrl() === 'https://cp-switch.example', 'M: host from one file');
assertAud021($deployment->controlPanelApiBaseUrl() === 'https://cp-switch.example/api/v1', 'M: API base derived');

file_put_contents($envTmp, "<?php\nreturn ['control_panel_url' => 'https://other.example'];\n");
$deployment2 = new ModuleDeploymentEnvironment($envTmp);
assertAud021($deployment2->controlPanelApiBaseUrl() === 'https://other.example/api/v1', 'M: switch requires only that file');

// Production src scan: no duplicated host outside config/environment.php
$srcPhp = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($iterator as $file) {
    if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
        $contents = (string) file_get_contents($file->getPathname());
        if (strpos($contents, 'uni.avalonbg.com') !== false) {
            $srcPhp[] = $file->getPathname();
        }
    }
}
assertAud021($srcPhp === [], 'L: no uni.avalonbg.com literals in src/');

@unlink($keyFile);
@unlink($envTmp);
@rmdir($tmp);

fwrite(STDOUT, "OK (AUD-021 mTLS passphrase contracts)\n");
