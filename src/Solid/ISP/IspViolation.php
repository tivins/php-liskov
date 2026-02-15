<?php

declare(strict_types=1);

namespace Tivins\Solid\ISP;

/**
 * Represents a single Interface Segregation Principle violation.
 */
readonly class IspViolation
{
    public function __construct(
        public string $className,
        public string $interfaceName,
        public string $reason,
        public ?string $details = null,
    ) {
    }

    public function __toString(): string
    {
        $out = sprintf(
            '%s — interface %s — %s',
            $this->className,
            $this->interfaceName,
            $this->reason,
        );
        if ($this->details !== null && $this->details !== '') {
            $out .= "\n         " . str_replace("\n", "\n         ", $this->details);
        }
        return $out;
    }
}
