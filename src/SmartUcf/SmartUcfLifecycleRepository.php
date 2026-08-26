<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Order\FinancingSnapshotRepository;

/**
 * Atomic SmartUCF lifecycle persistence on financing snapshots (AUD-002B).
 */
final class SmartUcfLifecycleRepository
{
    /**
     * Stale submitting grace after claim (client timeout is ~10s).
     * Must not auto-retry create-session.
     */
    public const STALE_SUBMITTING_SECONDS = 45;

    /** @var \Db */
    private $database;

    public function __construct(?\Db $database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    /** @return array<string, mixed>|null */
    public function findByAttempt(int $attemptId): ?array
    {
        if ($attemptId <= 0) {
            return null;
        }
        $row = $this->database->getRow(
            'SELECT `id_snapshot`, `id_attempt`, `id_order`, `order_reference`,
                    `smartucf_state`, `smartucf_session_id`, `smartucf_redirect_url`,
                    `smartucf_http_code`, `smartucf_error_class`, `smartucf_retryable`,
                    `smartucf_claimed_at`, `smartucf_completed_at`
             FROM `' . _DB_PREFIX_ . FinancingSnapshotRepository::TABLE . '`
             WHERE `id_attempt` = ' . (int) $attemptId
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Escalates stale submitting → outcome_unknown, then returns current row.
     *
     * @return array<string, mixed>|null
     */
    public function readAndNormalize(int $attemptId): ?array
    {
        $row = $this->findByAttempt($attemptId);
        if ($row === null) {
            return null;
        }
        if (
            (string) ($row['smartucf_state'] ?? '') === SmartUcfLifecycleStates::SUBMITTING
            && $this->isStaleSubmitting($row)
        ) {
            try {
                $this->markOutcomeUnknown(
                    $attemptId,
                    SmartUcfFailureClassification::CLASS_TRANSPORT_AMBIGUOUS,
                    (int) ($row['smartucf_http_code'] ?? 0)
                );
            } catch (SmartUcfLifecyclePersistenceException $exception) {
                // Keep row as-is; caller must not invent a persisted terminal state.
            }
            $row = $this->findByAttempt($attemptId) ?? $row;
        }

        return $row;
    }

    /**
     * Atomic claim: not_started → submitting, or failed+retryable → submitting.
     *
     * @return array<string, mixed>|null Winner row, or null if claim lost
     */
    public function claimForSubmitting(int $attemptId): ?array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            "UPDATE `%s%s` SET
                `smartucf_state` = '%s',
                `smartucf_claimed_at` = '%s',
                `smartucf_error_class` = NULL,
                `smartucf_http_code` = NULL,
                `smartucf_retryable` = 0,
                `smartucf_completed_at` = NULL,
                `updated_at` = '%s'
             WHERE `id_attempt` = %d
               AND (
                    `smartucf_state` = '%s'
                    OR (`smartucf_state` = '%s' AND `smartucf_retryable` = 1)
               )",
            _DB_PREFIX_,
            FinancingSnapshotRepository::TABLE,
            pSQL(SmartUcfLifecycleStates::SUBMITTING),
            pSQL($now),
            pSQL($now),
            $attemptId,
            pSQL(SmartUcfLifecycleStates::NOT_STARTED),
            pSQL(SmartUcfLifecycleStates::FAILED)
        );
        if (!$this->database->execute($sql)) {
            throw new \RuntimeException('The SmartUCF lifecycle claim could not be executed.');
        }
        if ((int) $this->database->Affected_Rows() !== 1) {
            return null;
        }

        return $this->findByAttempt($attemptId);
    }

    public function markCreated(
        int $attemptId,
        string $sessionId,
        string $redirectUrl,
        int $httpCode
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->database->update(
            FinancingSnapshotRepository::TABLE,
            [
                'smartucf_state' => SmartUcfLifecycleStates::CREATED,
                'smartucf_session_id' => pSQL(substr($sessionId, 0, 128)),
                'smartucf_redirect_url' => pSQL(substr($redirectUrl, 0, 768)),
                'smartucf_http_code' => $httpCode,
                'smartucf_error_class' => '',
                'smartucf_retryable' => 0,
                'smartucf_completed_at' => $now,
                'updated_at' => $now,
            ],
            '`id_attempt` = ' . (int) $attemptId . " AND `smartucf_state` = '" . pSQL(SmartUcfLifecycleStates::SUBMITTING) . "'"
        );
        if (!$ok || (int) $this->database->Affected_Rows() !== 1) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF created transition did not update exactly one submitting row.'
            );
        }
    }

    public function markOutcomeUnknown(int $attemptId, string $errorClass, int $httpCode = 0): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->database->update(
            FinancingSnapshotRepository::TABLE,
            [
                'smartucf_state' => SmartUcfLifecycleStates::OUTCOME_UNKNOWN,
                'smartucf_error_class' => pSQL(substr($errorClass, 0, 64)),
                'smartucf_http_code' => $httpCode > 0 ? $httpCode : null,
                'smartucf_retryable' => 0,
                'smartucf_completed_at' => $now,
                'updated_at' => $now,
            ],
            '`id_attempt` = ' . (int) $attemptId
                . " AND `smartucf_state` IN ('"
                . pSQL(SmartUcfLifecycleStates::SUBMITTING) . "','"
                . pSQL(SmartUcfLifecycleStates::NOT_STARTED) . "')"
        );
        if (!$ok || (int) $this->database->Affected_Rows() !== 1) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF outcome_unknown transition did not update exactly one eligible row.'
            );
        }
    }

    public function markFailed(int $attemptId, string $errorClass, bool $retryable, int $httpCode = 0): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $ok = $this->database->update(
            FinancingSnapshotRepository::TABLE,
            [
                'smartucf_state' => SmartUcfLifecycleStates::FAILED,
                'smartucf_error_class' => pSQL(substr($errorClass, 0, 64)),
                'smartucf_http_code' => $httpCode > 0 ? $httpCode : null,
                'smartucf_retryable' => $retryable ? 1 : 0,
                'smartucf_completed_at' => $now,
                'updated_at' => $now,
            ],
            '`id_attempt` = ' . (int) $attemptId
                . " AND `smartucf_state` = '" . pSQL(SmartUcfLifecycleStates::SUBMITTING) . "'"
        );
        if (!$ok || (int) $this->database->Affected_Rows() !== 1) {
            throw new SmartUcfLifecyclePersistenceException(
                'The SmartUCF failed transition did not update exactly one submitting row.'
            );
        }
    }

    /** @param array<string, mixed> $row */
    public function isStaleSubmitting(array $row): bool
    {
        if ((string) ($row['smartucf_state'] ?? '') !== SmartUcfLifecycleStates::SUBMITTING) {
            return false;
        }
        $claimed = (string) ($row['smartucf_claimed_at'] ?? '');
        if ($claimed === '') {
            return true;
        }
        $claimedTs = strtotime($claimed . ' UTC');
        if ($claimedTs === false) {
            return true;
        }

        return (time() - $claimedTs) >= self::STALE_SUBMITTING_SECONDS;
    }
}
