<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Product;

/**
 * Authoritative popup submission gate: token, shop, identity, selection, atomic claim.
 *
 * Distinct from Phase 4 HMAC nonce replay (CP → module).
 */
final class ProductPopupOperationGuard
{
    public const CUSTOMER_PROCESSING = 'Заявката се обработва. Моля, изчакайте.';

    /** @var PopupSubmissionRepository */
    private $submissions;

    public function __construct(?PopupSubmissionRepository $submissions = null)
    {
        $this->submissions = $submissions ?? new PopupSubmissionRepository();
    }

    /**
     * @return array{response?: array<string, mixed>, submission?: array<string, mixed>}
     */
    public function resolve(
        string $token,
        string $selectionHash,
        int $idShop,
        int $idGuest,
        int $idCustomer
    ): array {
        if ($token === '') {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Липсва token за popup заявката.']];
        }

        $row = $this->submissions->findByToken($token);
        if ($row === null) {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Невалиден token за popup заявката.']];
        }

        if ((int) $row['id_shop'] !== $idShop) {
            http_response_code(400);

            return ['response' => ['success' => false, 'message' => 'Невалиден token за popup заявката.']];
        }

        if (!$this->identityMatches($row, $idGuest, $idCustomer) || !hash_equals((string) $row['selection_hash'], $selectionHash)) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'Избраният план за финансиране е променен. Моля, продължете от Стъпка 1.',
                    'selection_changed' => true,
                ],
            ];
        }

        $state = (string) $row['state'];
        if ($state === PopupSubmissionStates::IDENTITY_ACCEPTED) {
            if ($this->submissions->isExpired($row)) {
                http_response_code(409);

                return [
                    'response' => [
                        'success' => false,
                        'message' => 'Token за popup заявката е изтекъл. Моля, продължете от Стъпка 1.',
                    ],
                ];
            }

            return ['response' => $this->identityAcceptedResponse($row, true)];
        }

        if ($state === PopupSubmissionStates::ORDER_CREATED && (int) ($row['id_order'] ?? 0) > 0) {
            return ['response' => $this->existingOrderResponse($row)];
        }

        if ($state === PopupSubmissionStates::FAILED) {
            http_response_code(409);

            return [
                'response' => [
                    'success' => false,
                    'message' => 'Тази заявка за финансиране вече не може да се използва. Моля, започнете отново.',
                ],
            ];
        }

        if ($state === PopupSubmissionStates::PROCESSING) {
            $idCart = (int) ($row['id_cart'] ?? 0);
            if ($idCart <= 0) {
                return ['response' => $this->processingResponse($token)];
            }

            return ['submission' => $row];
        }

        if ($state === PopupSubmissionStates::ISSUED) {
            if ($this->submissions->isExpired($row)) {
                http_response_code(409);

                return [
                    'response' => [
                        'success' => false,
                        'message' => 'Token за popup заявката е изтекъл. Моля, продължете от Стъпка 1.',
                    ],
                ];
            }

            $claimed = $this->submissions->claimForProcessing($token);
            if ($claimed !== null) {
                return ['submission' => $claimed];
            }

            $latest = $this->submissions->findByToken($token);
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::IDENTITY_ACCEPTED) {
                return ['response' => $this->identityAcceptedResponse($latest, true)];
            }
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::ORDER_CREATED) {
                return ['response' => $this->existingOrderResponse($latest)];
            }
            if (is_array($latest) && (string) $latest['state'] === PopupSubmissionStates::PROCESSING) {
                if ((int) ($latest['id_cart'] ?? 0) > 0) {
                    return ['submission' => $latest];
                }

                return ['response' => $this->processingResponse($token)];
            }

            return ['response' => $this->processingResponse($token)];
        }

        http_response_code(409);

        return [
            'response' => [
                'success' => false,
                'message' => 'Заявката е в неизвестно състояние.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function identityAcceptedResponse(array $row, bool $replay = false): array
    {
        $response = [
            'success' => true,
            'step' => 'identity_accepted',
            'popup_submission_token' => (string) $row['submission_token'],
            'message' => 'Данните са приети. Поръчката ще бъде завършена на следваща стъпка.',
        ];
        if ($replay) {
            $response['replay'] = true;
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function processingResponse(string $token): array
    {
        return [
            'success' => true,
            'step' => 'processing',
            'popup_submission_token' => $token,
            'message' => self::CUSTOMER_PROCESSING,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function existingOrderResponse(array $row): array
    {
        return [
            'success' => true,
            'step' => 'order_created',
            'replay' => true,
            'popup_submission_token' => (string) $row['submission_token'],
            'order' => [
                'id_order' => (int) $row['id_order'],
                'order_reference' => (string) $row['order_reference'],
                'control_panel_order_id' => (int) ($row['control_panel_order_id'] ?? 0),
                'id_attempt' => (int) ($row['id_attempt'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function identityMatches(array $row, int $idGuest, int $idCustomer): bool
    {
        $rowGuest = (int) ($row['id_guest'] ?? 0);
        $rowCustomer = (int) ($row['id_customer'] ?? 0);

        return $rowGuest === $idGuest && $rowCustomer === $idCustomer;
    }
}
