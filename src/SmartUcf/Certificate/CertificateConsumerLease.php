<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf\Certificate;

/**
 * Immutable PEM pair snapshot for one SmartUCF HTTP operation.
 */
final class CertificateConsumerLease
{
    /** @var string */
    private $directory;
    /** @var string */
    private $certificatePath;
    /** @var string */
    private $privateKeyPath;
    /** @var string */
    private $password;
    /** @var bool */
    private $released = false;

    public function __construct(
        string $directory,
        string $certificatePath,
        string $privateKeyPath,
        string $password
    ) {
        $this->directory = $directory;
        $this->certificatePath = $certificatePath;
        $this->privateKeyPath = $privateKeyPath;
        $this->password = $password;
    }

    public function certificatePath(): string
    {
        return $this->certificatePath;
    }

    public function privateKeyPath(): string
    {
        return $this->privateKeyPath;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        foreach ([$this->certificatePath, $this->privateKeyPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
