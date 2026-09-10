<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Security;

/**
 * Bounded raw HTTP body reader for signed CP→module requests.
 *
 * Reads at most MAX+1 bytes so oversized bodies are detected without unbounded buffering.
 */
final class BoundedRawBodyReader
{
    /**
     * @param resource $stream
     * @return array{body: string, oversized: bool}
     */
    public static function read($stream, int $maxBytes): array
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('maxBytes must be non-negative.');
        }

        $limit = $maxBytes + 1;
        $chunks = [];
        $total = 0;

        while (!feof($stream)) {
            $remaining = $limit - $total;
            if ($remaining <= 0) {
                return ['body' => '', 'oversized' => true];
            }

            $chunk = fread($stream, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $chunks[] = $chunk;
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                return [
                    'body' => '',
                    'oversized' => true,
                ];
            }
        }

        return [
            'body' => implode('', $chunks),
            'oversized' => false,
        ];
    }
}
