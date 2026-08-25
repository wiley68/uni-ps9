<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Checkout;

use PrestaShop\Module\Unipayment\Calculator\CalculationResult;

final class ValidatedPaymentRequest
{
    /** @var CalculationResult */
    public $calculation;
    /** @var array<string, string> */
    public $customer;
    /** @var int[] */
    public $acceptedConsentIds;
    /** @var array<int, array{id:int,name:string,url:string,mandatory:bool}> */
    public $acceptedConsents;
    /** @var string */
    public $cartFingerprint;

    /** @param array<string, string> $customer @param int[] $acceptedConsentIds @param array<int, array{id:int,name:string,url:string,mandatory:bool}> $acceptedConsents */
    public function __construct(CalculationResult $calculation, array $customer, array $acceptedConsentIds, string $cartFingerprint, array $acceptedConsents = [])
    {
        $this->calculation = $calculation;
        $this->customer = $customer;
        $this->acceptedConsentIds = $acceptedConsentIds;
        $this->acceptedConsents = $acceptedConsents;
        $this->cartFingerprint = $cartFingerprint;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scheme_type' => $this->calculation->scheme->type,
            'kop_code' => $this->calculation->scheme->kopCode,
            'months' => $this->calculation->scheme->months,
            'filter_id' => $this->calculation->scheme->filterId,
            'cart_total' => $this->calculation->price,
            'first_installment' => $this->calculation->firstInstallment->amount,
            'financed_amount' => $this->calculation->financedAmount,
            'monthly_installment' => $this->calculation->monthlyInstallment,
            'total_payable' => $this->calculation->totalPayable,
            'glp' => $this->calculation->glp,
            'gpr' => $this->calculation->gpr,
            'customer' => $this->customer,
            'accepted_consent_ids' => $this->acceptedConsentIds,
            'cart_fingerprint' => $this->cartFingerprint,
        ];
    }
}
