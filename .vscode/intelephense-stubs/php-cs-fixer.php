<?php

declare(strict_types=1);

namespace PhpCsFixer;

/**
 * Intelephense-only stub. PHP CS Fixer is not a module runtime dependency.
 */
class Finder
{
    public static function create(): self
    {
        return new self();
    }

    /**
     * @param string|string[] $dirs
     */
    public function in($dirs): self
    {
        return $this;
    }

    /**
     * @param string|string[] $dirs
     */
    public function exclude($dirs): self
    {
        return $this;
    }
}

class Config
{
    public function setRiskyAllowed(bool $riskyAllowed): self
    {
        return $this;
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function setRules(array $rules): self
    {
        return $this;
    }

    public function setFinder(Finder $finder): self
    {
        return $this;
    }
}
