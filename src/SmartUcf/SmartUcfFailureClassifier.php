<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Maps SmartUCF exceptions / responses to persisted lifecycle outcomes (AUD-008).
 */
final class SmartUcfFailureClassifier
{
    public function classify(SmartUcfSessionException $exception): SmartUcfFailureClassification
    {
        $httpCode = $exception->httpCode();
        $kind = $exception->getFailureKind();
        $raw = strtolower($exception->rawResponse() . ' ' . $exception->getMessage());

        if ($kind === SmartUcfSessionException::KIND_PRE_SEND) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::FAILED,
                true,
                SmartUcfFailureClassification::CLASS_PRE_SEND,
                $httpCode
            );
        }

        if ($kind === SmartUcfSessionException::KIND_DUPLICATE || $this->looksLikeDuplicateOrderNo($raw)) {
            // Duplicate proves an earlier submission for orderNo may exist, but redirect is unavailable.
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                SmartUcfFailureClassification::CLASS_DUPLICATE_ORDER_NO,
                $httpCode
            );
        }

        if ($kind === SmartUcfSessionException::KIND_TRANSPORT) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                $httpCode
            );
        }

        // KIND_REMOTE or unknown: definitive reject only when we have a parseable non-success response
        // without evidence the request was ambiguously accepted.
        if ($httpCode >= 500 || $httpCode === 0) {
            return new SmartUcfFailureClassification(
                SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                false,
                SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                $httpCode
            );
        }

        return new SmartUcfFailureClassification(
            SmartUcfLifecycleStates::FAILED,
            false,
            SmartUcfFailureClassification::CLASS_REMOTE_REJECT,
            $httpCode
        );
    }

    public function classifyThrowable(\Throwable $exception): SmartUcfFailureClassification
    {
        if ($exception instanceof SmartUcfSessionException) {
            return $this->classify($exception);
        }

        // Unexpected local errors before/around send — treat as pre-send failed (retryable).
        return new SmartUcfFailureClassification(
            SmartUcfLifecycleStates::FAILED,
            true,
            SmartUcfFailureClassification::CLASS_PRE_SEND,
            0
        );
    }

    private function looksLikeDuplicateOrderNo(string $haystack): bool
    {
        if ($haystack === '') {
            return false;
        }

        return (strpos($haystack, 'duplicate') !== false && strpos($haystack, 'order') !== false)
            || strpos($haystack, 'already exists') !== false
            || strpos($haystack, 'order already') !== false
            || strpos($haystack, 'съществува') !== false;
    }
}
