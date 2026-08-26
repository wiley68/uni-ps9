<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\SchemeSelection;
use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;

final class FinancingSnapshotFactory
{
    /** @var SensitiveDataCipher */
    private $cipher;

    public function __construct(SensitiveDataCipher $cipher)
    {
        $this->cipher = $cipher;
    }

    /** @return array<string, mixed> */
    public function create(ValidatedPaymentRequest $request, CreatedOrder $order, string $submissionSource = 'checkout'): array
    {
        $calculation = $request->calculation;
        $customer = $order->customer;
        $sensitive = [
            'egn' => (string) ($request->customer['egn'] ?? ''),
            'phone2' => (string) ($request->customer['phone2'] ?? ''),
        ];
        unset($customer['egn'], $customer['phone2']);

        // AUD-010: popup financing contact email may differ from Customer.email.
        if (in_array($submissionSource, ['product_popup', 'cart_popup'], true)) {
            $financingEmail = trim((string) ($request->customer['email'] ?? ''));
            if ($financingEmail !== '') {
                $customer['email'] = $financingEmail;
            }
        }

        return [
            'id_order' => $order->idOrder,
            'order_reference' => $order->reference,
            'cart_fingerprint' => $request->cartFingerprint,
            'scheme_type' => $calculation->scheme->type,
            'scheme_key' => SchemeSelection::key($calculation->scheme->type, $calculation->scheme->months, $calculation->scheme->filterId),
            'kop_code' => $calculation->scheme->kopCode,
            'months' => $calculation->scheme->months,
            'filter_id' => $calculation->scheme->filterId,
            'first_installment' => $calculation->firstInstallment->amount,
            'financed_amount' => $calculation->financedAmount,
            'monthly_installment' => $calculation->monthlyInstallment,
            'total_payable' => $calculation->totalPayable,
            'glp' => $calculation->glp,
            'gpr' => $calculation->gpr,
            'coefficient' => (float) ($calculation->scheme->coefficient['coeff'] ?? 0),
            'order_total' => $order->total,
            'currency_iso' => $order->currencyIso,
            'id_currency' => $order->idCurrency,
            'module_version' => '2.0.1',
            'submission_source' => $submissionSource,
            'customer_json' => $customer,
            'address_json' => $order->addresses,
            'lines_json' => $order->lines,
            'consents_json' => $request->acceptedConsents,
            'sensitive_payload' => $this->cipher->encrypt($sensitive),
            'control_panel_order_id' => null,
            'lifecycle_status' => OrderOrchestrator::PS_ORDER_CREATED,
            'smartucf_state' => 'not_started',
            'smartucf_retryable' => 0,
        ];
    }
}
