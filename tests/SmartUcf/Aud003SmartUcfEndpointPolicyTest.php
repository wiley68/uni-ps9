<?php

declare(strict_types=1);

/**
 * AUD-003 — SmartUCF endpoint trust boundary / SSRF regression tests.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfFailureClassifier;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfPayloadBuilder;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionClient;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfSessionException;

if (!class_exists('PrestaShopLogger', false)) {
    class PrestaShopLogger
    {
        /** @var list<string> */
        public static $messages = [];

        public static function addLog(string $message, int $severity = 1): void
        {
            self::$messages[] = $message;
        }
    }
}

function assertAud003(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$policy = new SmartUcfEndpointPolicy();

$prodService = 'https://online.ucfin.bg/suos/api/otp/';
$testService = 'https://onlinetest.ucfin.bg/suos/api/otp/';
$prodApp = 'https://online.ucfin.bg/sucf-online/Request/Start';
$testApp = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start';

// 1–2 legitimate bases accepted
assertAud003(
    $policy->buildSessionStartUrl($prodService) === 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    '1: production service accepted'
);
assertAud003(
    $policy->buildSessionStartUrl($testService) === 'https://onlinetest.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    '2: test service accepted'
);
assertAud003(
    $policy->assertTrustedApplicationBase($prodApp) === 'https://online.ucfin.bg/sucf-online/Request/Start',
    '1b: production application accepted'
);
assertAud003(
    $policy->assertTrustedApplicationBase($testApp) === 'https://onlinetest.ucfin.bg/sucf-online/Request/Start',
    '2b: test application accepted'
);
assertAud003(
    $policy->buildApplicationRedirect($prodApp, 'sess-abc.1') === 'https://online.ucfin.bg/sucf-online/Request/Start/sess-abc.1',
    '1c: production redirect built'
);

// Trailing-slash normalization
assertAud003(
    $policy->buildSessionStartUrl('https://online.ucfin.bg/suos/api/otp') === 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    'service base without trailing slash accepted'
);

$rejectCases = [
    '3 arbitrary HTTPS' => 'https://evil.example/suos/api/otp/',
    '4 localhost' => 'https://localhost/suos/api/otp/',
    '5 127.0.0.1' => 'https://127.0.0.1/suos/api/otp/',
    '6 private 10.x' => 'https://10.0.0.5/suos/api/otp/',
    '6b private 192.168' => 'https://192.168.1.1/suos/api/otp/',
    '7 metadata IP' => 'https://169.254.169.254/suos/api/otp/',
    '8 http scheme' => 'http://online.ucfin.bg/suos/api/otp/',
    '8b file scheme' => 'file:///etc/passwd',
    '8c ftp scheme' => 'ftp://online.ucfin.bg/suos/api/otp/',
    '9 userinfo' => 'https://user:pass@online.ucfin.bg/suos/api/otp/',
    '10 lookalike' => 'https://trusted.example.attacker.com/suos/api/otp/',
    '11 suffix trick' => 'https://online.ucfin.bg.attacker.com/suos/api/otp/',
    '11b prefix trick' => 'https://evil-online.ucfin.bg/suos/api/otp/',
    '12 unexpected port' => 'https://online.ucfin.bg:8443/suos/api/otp/',
    '13 unexpected path' => 'https://online.ucfin.bg/suos/api/other/',
    '13b path traversal' => 'https://online.ucfin.bg/suos/api/otp/../admin/',
    'fragment' => 'https://online.ucfin.bg/suos/api/otp/#x',
    'query' => 'https://online.ucfin.bg/suos/api/otp/?x=1',
];

foreach ($rejectCases as $label => $url) {
    $rejected = false;
    try {
        $policy->buildSessionStartUrl($url);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    assertAud003($rejected, $label . ' must be rejected');
}

$appReject = [
    'app arbitrary' => 'https://evil.example/sucf-online/Request/Start',
    'app wrong path' => 'https://online.ucfin.bg/other/Start',
    'app http' => 'http://online.ucfin.bg/sucf-online/Request/Start',
    'app userinfo' => 'https://u:p@online.ucfin.bg/sucf-online/Request/Start',
    'app port' => 'https://online.ucfin.bg:4443/sucf-online/Request/Start',
];
foreach ($appReject as $label => $url) {
    $rejected = false;
    try {
        $policy->assertTrustedApplicationBase($url);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    assertAud003($rejected, $label . ' must be rejected');
}

$redirectOk = $policy->buildApplicationRedirect($testApp, 'SID123');
assertAud003($policy->isTrustedApplicationRedirect($redirectOk), 'trusted redirect accepted');
assertAud003(!$policy->isTrustedApplicationRedirect('https://evil.example/sucf-online/Request/Start/SID123'), 'evil redirect rejected');
assertAud003(!$policy->isTrustedApplicationRedirect('https://online.ucfin.bg/sucf-online/Request/Start/../admin'), 'redirect traversal rejected');
assertAud003(!$policy->isTrustedApplicationRedirect('https://online.ucfin.bg/sucf-online/Request/Start/sess/extra'), 'redirect multi-segment session rejected');

// 14 FOLLOWLOCATION explicitly disabled in client source
$clientSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionClient.php');
assertAud003(strpos($clientSrc, 'CURLOPT_FOLLOWLOCATION => false') !== false, '14: FOLLOWLOCATION=false');
assertAud003(strpos($clientSrc, 'buildSessionStartUrl') !== false, '14: policy gate used');
assertAud003(
    strpos($clientSrc, 'buildSessionStartUrl') < strpos($clientSrc, 'curl_init'),
    '14: validation before curl_init'
);

// 15–16 invalid URL → zero outbound (no curl) + pre-send failure
$snapshot = [
    'order_reference' => 'AUD003REF01',
    'kop_code' => 'X',
    'order_total' => 100,
    'first_installment' => 0,
    'months' => 12,
    'monthly_installment' => 10,
    'customer_json' => [],
    'address_json' => [],
    'lines_json' => [],
];
$evilShop = [
    'uni_env' => 0,
    'uni_test_service' => 'https://127.0.0.1/suos/api/otp/',
    'uni_test_application' => $testApp,
    'uni_sertificat' => 0,
    '_currency_iso' => 'EUR',
];
$client = new SmartUcfSessionClient(new SmartUcfPayloadBuilder());
$preSendThrown = false;
$kind = '';
try {
    $client->createSession($evilShop, $snapshot);
} catch (SmartUcfSessionException $e) {
    $preSendThrown = true;
    $kind = $e->getFailureKind();
}
assertAud003($preSendThrown && $kind === SmartUcfSessionException::KIND_PRE_SEND, '15/16: evil service → PRE_SEND, no successful send');

$classifier = new SmartUcfFailureClassifier();
$classification = $classifier->classify(new SmartUcfSessionException(
    'The SmartUCF endpoint URL is not trusted.',
    true,
    '',
    0,
    SmartUcfSessionException::KIND_PRE_SEND
));
assertAud003($classification->targetState() === SmartUcfLifecycleStates::FAILED, '16: failed not outcome_unknown');
assertAud003($classification->isRetryable() === true, '16: retryable only after config fix');
assertAud003($classification->errorClass() === 'pre_send', '16: pre_send class');

// Evil application base also blocked before send
$evilAppShop = [
    'uni_env' => 1,
    'uni_production_service' => $prodService,
    'uni_production_application' => 'https://evil.example/sucf-online/Request/Start',
    'uni_sertificat' => 0,
];
$preSendApp = false;
try {
    $client->createSession($evilAppShop, $snapshot);
} catch (SmartUcfSessionException $e) {
    $preSendApp = $e->getFailureKind() === SmartUcfSessionException::KIND_PRE_SEND;
}
assertAud003($preSendApp, 'evil application → PRE_SEND before HTTP');

// Policy is module-owned (no CP-driven host expansion in source)
$policySrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfEndpointPolicy.php');
assertAud003(strpos($policySrc, 'online.ucfin.bg') !== false, 'policy embeds production host');
assertAud003(strpos($policySrc, 'onlinetest.ucfin.bg') !== false, 'policy embeds test host');
assertAud003(strpos($policySrc, 'uni_production_service') === false, 'policy does not read CP fields');

// Controllers / coordinator gate redirects
$coordSrc = (string) file_get_contents($root . '/src/SmartUcf/SmartUcfSessionCoordinator.php');
assertAud003(strpos($coordSrc, 'isTrustedApplicationRedirect') !== false, '17: coordinator validates replay redirect');
// Post-CP lifecycle service gates customer redirects (controllers stay transport-only)
$lifecycleSrc = (string) file_get_contents($root . '/src/Order/PostControlPanelLifecycleService.php');
assertAud003(strpos($lifecycleSrc, 'isTrustedApplicationRedirect') !== false, 'checkout blocks untrusted redirect');
$checkout = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
assertAud003(strpos($checkout, 'PostControlPanelLifecycleService') !== false, 'validatecheckout uses shared lifecycle');
$popup = (string) file_get_contents($root . '/controllers/front/productpopup.php');
assertAud003(strpos($popup, 'PostControlPanelLifecycleService') === false, 'product popup stays identity_accepted in Phase 11');

// 18 legitimate happy-path URL wiring unchanged in shape
assertAud003(
    $policy->buildSessionStartUrl($prodService) === 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
    '18: production session start URL unchanged'
);

fwrite(STDOUT, "OK (AUD-003 SmartUCF endpoint policy)\n");
