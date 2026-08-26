<?php

declare(strict_types=1);

/**
 * AUD-019 follow-up: checkout double-click / lock-loser UX.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\Checkout\CheckoutLockLoserRecovery;
use PrestaShop\Module\Unipayment\Order\OrderOrchestrator;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfEndpointPolicy;
use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfLifecycleStates;

function assertDbl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$js = (string) file_get_contents($root . '/views/js/checkout-payment.js');
$ctrl = (string) file_get_contents($root . '/controllers/front/validatecheckout.php');
$processingTpl = (string) file_get_contents($root . '/views/templates/front/checkout_processing.tpl');
$errorTpl = (string) file_get_contents($root . '/views/templates/front/checkout_validation_error.tpl');

// A/B client guard contracts — first click must NOT disable before native submit
assertDbl(strpos($js, 'submitState') !== false, 'A: submitState model present');
assertDbl(strpos($js, 'click_accepted') !== false, 'A: click_accepted state');
assertDbl(strpos($js, 'acceptFirstClick') !== false, 'A: acceptFirstClick helper');
assertDbl(strpos($js, 'validateBeforeSubmit') !== false, 'A/D: validateBeforeSubmit present');
assertDbl(strpos($js, 'validateFormFields') !== false, 'C: field validation separated from guard');

$acceptBody = '';
if (preg_match('/function acceptFirstClick\(\)\s*\{([\s\S]*?)\n        \}\n\n        if \(select\)/', $js, $acceptMatch)) {
    $acceptBody = $acceptMatch[1];
}
assertDbl($acceptBody !== '', 'A: acceptFirstClick body extractable');
assertDbl(
    strpos($acceptBody, '.disabled') === false,
    'A: acceptFirstClick must not disable the submitter'
);
assertDbl(
    strpos($js, 'unipayment-checkout--click-accepted') !== false,
    'A: optional click_accepted visual class without disabling submitter'
);
assertDbl(
    (bool) preg_match(
        '/function markSubmitting\(\)\s*\{[\s\S]*?\.disabled\s*=\s*true/',
        $js
    ),
    'A: button disabled only in markSubmitting (during submit)'
);
assertDbl(
    (bool) preg_match('/click_accepted[\s\S]{0,200}markSubmitting/s', $js),
    'A: first form submit after click_accepted proceeds via markSubmitting'
);
assertDbl(
    (bool) preg_match(
        '/submitState === "click_accepted" \|\| submitState === "submitting"[\s\S]{0,160}preventDefault/s',
        $js
    ),
    'B: second click while click_accepted/submitting prevented'
);
assertDbl(
    (bool) preg_match(
        '/document\.addEventListener\(\s*"click",[\s\S]*?if \(!validateBeforeSubmit\(\)\) \{[\s\S]*?return;[\s\S]*?acceptFirstClick\(\);/s',
        $js
    ),
    'C: acceptFirstClick only after successful validateBeforeSubmit on click path'
);
assertDbl(
    (bool) preg_match(
        '/submitState === "click_accepted"[\s\S]{0,200}markSubmitting[\s\S]{0,200}validateBeforeSubmit/s',
        $js
    ),
    'D: idle/direct submit path still validates via validateBeforeSubmit'
);
assertDbl(
    (bool) preg_match(
        '/submitState === "submitting"[\s\S]{0,80}preventDefault/s',
        $js
    ),
    'E: repeated submit while submitting blocked'
);
assertDbl(
    strpos($acceptBody, 'submitState = "idle"') !== false,
    'failure recovery: unlock if submit never began after click_accepted'
);
assertDbl(!preg_match('/\$\s*\(|\bjQuery\b/', $js), 'no jQuery');
assertDbl(strpos($js, 'stopImmediatePropagation') !== false, 'B: second click stopImmediatePropagation available');

// C/D/H server lock-loser contracts
assertDbl(strpos($ctrl, 'CheckoutLockLoserRecovery') !== false, 'C/D: lock loser recovery wired');
assertDbl(strpos($ctrl, 'handleLockLoser') !== false, 'C/D: handleLockLoser');
assertDbl(
    !preg_match(
        '/acquire\(\$idShop,\s*\$idCart\);\s*if \(\$lockToken === null\) \{\s*\$this->showPreOrderError/s',
        $ctrl
    ),
    'C: lock loser must not immediately showPreOrderError'
);
assertDbl(strpos($ctrl, 'checkout_processing.tpl') !== false, 'C: processing template for pre-order contention');
assertDbl(strpos($processingTpl, 'Обратно към checkout') === false, 'C: processing has no back-to-checkout CTA');
assertDbl(strpos($errorTpl, 'Обратно към checkout') !== false, 'H: true validation error still has return link');

// Fake recovery dependencies
final class DblFakeAttempts
{
    /** @var array<string, mixed>|null */
    public $row;

    public function findLatestByShopCart(int $idShop, int $idCart): ?array
    {
        unset($idShop, $idCart);

        return $this->row;
    }
}

final class DblFakeSnapshots
{
    /** @var array<string, mixed>|null */
    public $byOrder;
    /** @var array<string, mixed>|null */
    public $byAttempt;

    public function findByOrderId(int $idOrder): ?array
    {
        unset($idOrder);

        return $this->byOrder;
    }

    public function findByAttempt(int $attemptId): ?array
    {
        unset($attemptId);

        return $this->byAttempt;
    }
}

$attempts = new DblFakeAttempts();
$snapshots = new DblFakeSnapshots();
$policy = new SmartUcfEndpointPolicy();

// C. lock loser before PS order
$recoveryC = new CheckoutLockLoserRecovery(
    // @phpstan-ignore-next-line intentional test double
    $attempts,
    // @phpstan-ignore-next-line
    $snapshots,
    $policy,
    static function (int $idCart): int {
        unset($idCart);

        return 0;
    }
);
$resolvedC = $recoveryC->resolve(1, 9);
assertDbl($resolvedC['kind'] === CheckoutLockLoserRecovery::KIND_PROCESSING, 'C: no order → processing');
assertDbl((int) $resolvedC['id_order'] === 0, 'C: no fabricated order');

// D. lock loser after PS order
$attempts->row = [
    'id_attempt' => 7,
    'id_order' => 55,
    'order_reference' => 'DBLCLKORDER1',
    'control_panel_order_id' => 0,
    'state' => OrderOrchestrator::PS_ORDER_CREATED,
];
$recoveryD = new CheckoutLockLoserRecovery(
    $attempts,
    $snapshots,
    $policy,
    static function (int $idCart): int {
        unset($idCart);

        return 55;
    }
);
$resolvedD = $recoveryD->resolve(1, 9);
assertDbl($resolvedD['kind'] === CheckoutLockLoserRecovery::KIND_CONFIRMATION, 'D: existing order → confirmation');
assertDbl((int) $resolvedD['id_order'] === 55, 'D: recovers id_order');

// E. after CP success (no SmartUCF redirect yet) → confirmation, no CP call in recovery class
$attempts->row['control_panel_order_id'] = 901;
$attempts->row['state'] = OrderOrchestrator::CP_CREATED;
$snapshots->byOrder = [
    'order_reference' => 'DBLCLKORDER1',
    'control_panel_order_id' => 901,
    'smartucf_state' => SmartUcfLifecycleStates::NOT_STARTED,
    'smartucf_redirect_url' => '',
];
$resolvedE = $recoveryD->resolve(1, 9);
assertDbl($resolvedE['kind'] === CheckoutLockLoserRecovery::KIND_CONFIRMATION, 'E: CP success → confirmation observer');
assertDbl((int) $resolvedE['control_panel_order_id'] === 901, 'E: reuses CP id');

// F. SmartUCF created with trusted redirect
$snapshots->byOrder = [
    'order_reference' => 'DBLCLKORDER1',
    'control_panel_order_id' => 901,
    'smartucf_state' => SmartUcfLifecycleStates::CREATED,
    'smartucf_redirect_url' => 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/SID123',
];
$resolvedF = $recoveryD->resolve(1, 9);
assertDbl($resolvedF['kind'] === CheckoutLockLoserRecovery::KIND_SMARTUCF_REDIRECT, 'F: durable SmartUCF redirect reused');
assertDbl($resolvedF['redirect_url'] !== '', 'F: redirect present');

// Untrusted redirect must not be followed
$snapshots->byOrder['smartucf_redirect_url'] = 'https://evil.example/sucf-online/Request/Start/SID123';
$resolvedBad = $recoveryD->resolve(1, 9);
assertDbl($resolvedBad['kind'] !== CheckoutLockLoserRecovery::KIND_SMARTUCF_REDIRECT, 'F: untrusted redirect rejected');

// Outcome unknown
$snapshots->byOrder = [
    'order_reference' => 'DBLCLKORDER1',
    'control_panel_order_id' => 901,
    'smartucf_state' => SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
    'smartucf_redirect_url' => '',
];
$resolvedU = $recoveryD->resolve(1, 9);
assertDbl($resolvedU['kind'] === CheckoutLockLoserRecovery::KIND_OUTCOME_UNKNOWN, 'outcome unknown preserved');

// G. concurrency contracts on controller source
assertDbl(strpos($ctrl, 'OrderOrchestrator') !== false, 'G: winner still uses orchestrator');
assertDbl(
    (bool) preg_match('/handleLockLoser[\s\S]*CheckoutLockLoserRecovery/s', $ctrl),
    'G: loser uses recovery observer only'
);
assertDbl(strpos($ctrl, 'createSession') === false, 'G: validatecheckout must not create SmartUCF directly');

// I. Product Купи unchanged smoke
$kupi = (string) file_get_contents($root . '/src/Product/ProductPopupCheckoutPreselectionService.php');
assertDbl(strpos($kupi, 'product_preselect') !== false, 'I: Product Купи preselect intact');

fwrite(STDOUT, "OK (AUD-019 checkout double-click / lock-loser UX)\n");
