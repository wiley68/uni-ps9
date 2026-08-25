<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * UniCredit bank-status identifiers aligned with wiley68/uni-woo / uni-ps8.
 */
final class BankStatus
{
    public const SENT_PROCESS1 = 'bank_sent_process1';
    public const SENT_PROCESS2 = 'bank_sent_process2';
    public const SEND_FAILED = 'bank_send_failed';
    public const SEND_FAILED_CP = 'bank_send_failed_cp';
    public const SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';

    public const LABEL_SENT_PROCESS1 = 'Изпратен Банка - Процес 1';
    public const LABEL_SENT_PROCESS2 = 'Изпратен Банка - Процес 2';
    public const LABEL_SEND_FAILED = 'Неуспешно изпратен Банка';
    public const LABEL_SEND_FAILED_CP = 'Неуспешно изпратен Банка - КП';
    public const LABEL_SEND_FAILED_SMARTUCF = 'Неуспешно изпратен Банка - SmartUCF';

    /** @return array{status_id: string, status_label: string} */
    public static function successfulSend(bool $process2): array
    {
        return $process2
            ? ['status_id' => self::SENT_PROCESS2, 'status_label' => self::LABEL_SENT_PROCESS2]
            : ['status_id' => self::SENT_PROCESS1, 'status_label' => self::LABEL_SENT_PROCESS1];
    }

    /** @return array{status_id: string, status_label: string} */
    public static function smartUcfFailure(): array
    {
        return [
            'status_id' => self::SEND_FAILED_SMARTUCF,
            'status_label' => self::LABEL_SEND_FAILED_SMARTUCF,
        ];
    }

    /**
     * Woo parity: Process 1 CP create failure uses bank_send_failed_cp;
     * Process 2 uses the generic bank_send_failed label.
     *
     * @return array{status_id: string, status_label: string}
     */
    public static function controlPanelFailure(bool $process2 = false): array
    {
        return $process2
            ? ['status_id' => self::SEND_FAILED, 'status_label' => self::LABEL_SEND_FAILED]
            : ['status_id' => self::SEND_FAILED_CP, 'status_label' => self::LABEL_SEND_FAILED_CP];
    }
}
