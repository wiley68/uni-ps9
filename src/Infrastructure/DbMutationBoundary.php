<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Infrastructure;

/**
 * Shop-scoped MySQL named lock + transaction boundary for multi-statement mutations/reads.
 */
final class DbMutationBoundary implements MutationBoundaryInterface
{
    public const LOCK_TIMEOUT_SECONDS = 15;

    /** @var \Db|object */
    private $database;

    /**
     * @param \Db|object|null $database
     */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function runExclusive(string $lockName, callable $callback)
    {
        $lockName = $this->normalizeLockName($lockName);
        $escaped = pSQL($lockName);
        $locked = (int) $this->database->getValue(
            'SELECT GET_LOCK(\'' . $escaped . '\', ' . self::LOCK_TIMEOUT_SECONDS . ')'
        );
        if ($locked !== 1) {
            throw new \RuntimeException(
                'Unable to acquire exclusive mutation lock for SmartUCF credential persistence.'
            );
        }

        $committed = false;
        try {
            if (!$this->database->execute('START TRANSACTION')) {
                throw new \RuntimeException('Unable to start SmartUCF credential persistence transaction.');
            }
            try {
                $result = $callback();
                if (!$this->database->execute('COMMIT')) {
                    throw new \RuntimeException('Unable to commit SmartUCF credential persistence transaction.');
                }
                $committed = true;

                return $result;
            } catch (\Throwable $exception) {
                $rollbackOk = false;
                try {
                    $rollbackOk = (bool) $this->database->execute('ROLLBACK');
                } catch (\Throwable $rollbackException) {
                    $this->logBoundaryFailure(
                        'ROLLBACK threw after mutation error: ' . $rollbackException->getMessage()
                    );
                    throw new \RuntimeException(
                        'SmartUCF credential persistence failed and database ROLLBACK also failed.',
                        0,
                        $exception
                    );
                }
                if (!$rollbackOk) {
                    $this->logBoundaryFailure('ROLLBACK returned unsuccessful status after mutation error.');
                    throw new \RuntimeException(
                        'SmartUCF credential persistence failed and database ROLLBACK also failed.',
                        0,
                        $exception
                    );
                }
                if (class_exists('\\Configuration') && method_exists('\\Configuration', 'resetStaticCache')) {
                    \Configuration::resetStaticCache();
                    if (method_exists('\\Configuration', 'loadConfiguration')) {
                        \Configuration::loadConfiguration();
                    }
                }

                throw $exception;
            }
        } finally {
            $this->releaseAdvisoryLock($escaped, $committed);
        }
    }

    public static function smartUcfCredentialLockName(int $idShop, string $unicid): string
    {
        $unicidHash = substr(hash('sha256', trim($unicid)), 0, 24);

        return 'unipay_sucf_cred_' . (int) $idShop . '_' . $unicidHash;
    }

    private function releaseAdvisoryLock(string $escapedLockName, bool $mutationCommitted): void
    {
        try {
            $released = $this->database->getValue(
                'SELECT RELEASE_LOCK(\'' . $escapedLockName . '\')'
            );
        } catch (\Throwable $releaseException) {
            $this->logBoundaryFailure(
                'RELEASE_LOCK threw'
                . ($mutationCommitted ? ' after successful COMMIT' : '')
                . ': ' . $releaseException->getMessage()
            );
            // Post-commit release failures must not convert a committed write into a retryable mutation failure.
            if (!$mutationCommitted) {
                throw new \RuntimeException(
                    'SmartUCF credential advisory lock RELEASE_LOCK failed.',
                    0,
                    $releaseException
                );
            }

            return;
        }

        // MySQL: 1 = released, 0 = not owner, NULL = lock name unknown.
        if ((int) $released !== 1) {
            $this->logBoundaryFailure(
                'RELEASE_LOCK returned unsuccessful result ('
                . var_export($released, true)
                . ')'
                . ($mutationCommitted ? ' after successful COMMIT' : '')
            );
            if (!$mutationCommitted) {
                throw new \RuntimeException(
                    'SmartUCF credential advisory lock was not released successfully.'
                );
            }
        }
    }

    private function logBoundaryFailure(string $message): void
    {
        if (class_exists('\\PrestaShopLogger')) {
            \PrestaShopLogger::addLog('UniPayment SmartUCF DbMutationBoundary: ' . $message, 3);
        }
    }

    private function normalizeLockName(string $lockName): string
    {
        $lockName = trim($lockName);
        if ($lockName === '' || strlen($lockName) > 64) {
            throw new \InvalidArgumentException('Mutation lock name must be 1–64 characters.');
        }

        return $lockName;
    }
}
