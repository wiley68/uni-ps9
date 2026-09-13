<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * Classification of an incoming SmartUCF credential pair from a shop snapshot.
 */
final class SmartUcfCredentialPairClassifier
{
    public const ABSENT = 'absent';
    public const COMPLETE = 'complete';
    public const INVALID = 'invalid';

    /**
     * @param array<string, mixed> $shopData
     */
    public static function classify(array $shopData): string
    {
        $userPresent = array_key_exists('uni_user', $shopData);
        $passwordPresent = array_key_exists('uni_password', $shopData);

        if (!$userPresent && !$passwordPresent) {
            return self::ABSENT;
        }

        if (!$userPresent || !$passwordPresent) {
            return self::INVALID;
        }

        $user = $shopData['uni_user'];
        $password = $shopData['uni_password'];
        if (!is_string($user) || !is_string($password)) {
            return self::INVALID;
        }

        if (trim($user) === '' || trim($password) === '') {
            return self::INVALID;
        }

        return self::COMPLETE;
    }

    /**
     * Remove SmartUCF credentials from a snapshot destined for general shop_data cache.
     *
     * @param array<string, mixed> $shopData
     *
     * @return array<string, mixed>
     */
    public static function stripFromSnapshot(array $shopData): array
    {
        return self::stripNode($shopData);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function stripNode(array $node): array
    {
        $out = [];
        $isList = $node === [] || array_keys($node) === range(0, count($node) - 1);

        foreach ($node as $key => $value) {
            if (!$isList && is_string($key) && ($key === 'uni_user' || $key === 'uni_password')) {
                continue;
            }

            if (is_array($value)) {
                $value = self::stripNode($value);
            }

            if ($isList) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
