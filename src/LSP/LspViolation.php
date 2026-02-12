<?php

declare(strict_types=1);

namespace Tivins\LSP;

/**
 * Represents a single Liskov Substitution Principle violation.
 */
readonly class LspViolation
{
    public function __construct(
        public string $className,
        public string $methodName,
        public string $contractName,
        public string $reason,
    ) {
    }

    public function __toString(): string
    {
        return sprintf(
            '%s::%s() — contract %s — %s',
            $this->className,
            $this->methodName,
            $this->contractName,
            $this->reason,
        );
    }
}
