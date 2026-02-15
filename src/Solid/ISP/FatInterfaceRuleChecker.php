<?php

declare(strict_types=1);

namespace Tivins\Solid\ISP;

use ReflectionClass;

/**
 * Detects "fat interfaces" — interfaces with more methods than a configurable threshold.
 *
 * An interface with too many methods is a code smell suggesting it should be split
 * into smaller, more focused interfaces.
 *
 * The violation is reported once per (class, interface) pair, not per method.
 */
class FatInterfaceRuleChecker implements IspRuleCheckerInterface
{
    public const DEFAULT_THRESHOLD = 5;

    /**
     * @var array<string, bool> Track already-reported interfaces to avoid duplicate reports
     *                          across different implementing classes.
     */
    private array $reported = [];

    public function __construct(
        private readonly int $threshold = self::DEFAULT_THRESHOLD,
    ) {
    }

    public function check(ReflectionClass $class, ReflectionClass $interface): array
    {
        // Only report a fat interface once (for the first class we encounter)
        $interfaceName = $interface->getName();
        if (isset($this->reported[$interfaceName])) {
            return [];
        }

        $methodCount = count($interface->getMethods());
        if ($methodCount <= $this->threshold) {
            return [];
        }

        $this->reported[$interfaceName] = true;

        $methodNames = array_map(
            fn(\ReflectionMethod $m) => $m->getName(),
            $interface->getMethods(),
        );

        return [
            new IspViolation(
                className: $class->getName(),
                interfaceName: $interfaceName,
                reason: sprintf(
                    'Interface has %d methods (threshold: %d) — consider splitting into smaller interfaces.',
                    $methodCount,
                    $this->threshold,
                ),
                details: 'Methods: ' . implode(', ', $methodNames),
            ),
        ];
    }
}
