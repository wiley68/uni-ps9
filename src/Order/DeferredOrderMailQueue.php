<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

/**
 * Holds Process 1 order_conf until SmartUCF returns the final bank status.
 */
final class DeferredOrderMailQueue
{
    /** @var bool */
    private static $active = false;

    /** @var array<int, array<string, mixed>> */
    private static $queue = [];

    public static function start(): void
    {
        self::$active = true;
        self::$queue = [];
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return bool True when Mail::Send should continue
     */
    public static function intercept(array $params): bool
    {
        if (!self::$active) {
            return true;
        }

        $template = (string) ($params['template'] ?? '');
        if ($template !== 'order_conf') {
            return true;
        }

        self::$queue[] = [
            'idLang' => (int) ($params['idLang'] ?? 0),
            'template' => $template,
            'subject' => (string) ($params['subject'] ?? ''),
            'templateVars' => is_array($params['templateVars'] ?? null) ? $params['templateVars'] : [],
            'to' => $params['to'] ?? null,
            'toName' => $params['toName'] ?? null,
            'from' => $params['from'] ?? null,
            'fromName' => $params['fromName'] ?? null,
            'fileAttachment' => $params['fileAttachment'] ?? null,
            'templatePath' => $params['templatePath'] ?? _PS_MAIL_DIR_,
            'idShop' => $params['idShop'] ?? null,
            'bcc' => $params['bcc'] ?? null,
            'replyTo' => $params['replyTo'] ?? null,
        ];

        return false;
    }

    /**
     * @param array<string, string> $leasingVars
     */
    public static function flush(array $leasingVars = []): void
    {
        self::$active = false;
        $queued = self::$queue;
        self::$queue = [];

        foreach ($queued as $mail) {
            $templateVars = is_array($mail['templateVars']) ? $mail['templateVars'] : [];
            $templateVars = array_merge($templateVars, $leasingVars);
            try {
                \Mail::Send(
                    (int) $mail['idLang'],
                    (string) $mail['template'],
                    (string) $mail['subject'],
                    $templateVars,
                    $mail['to'],
                    $mail['toName'],
                    $mail['from'],
                    $mail['fromName'],
                    $mail['fileAttachment'],
                    null,
                    $mail['templatePath'],
                    false,
                    $mail['idShop'] !== null ? (int) $mail['idShop'] : null,
                    $mail['bcc'],
                    $mail['replyTo']
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment deferred order email could not be sent: ' . $exception->getMessage(),
                    2
                );
            }
        }
    }

    public static function discard(): void
    {
        self::$active = false;
        self::$queue = [];
    }
}
