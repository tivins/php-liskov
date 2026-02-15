<?php

declare(strict_types=1);

namespace Tivins\Solid\ISP;

use ReflectionClass;

/**
 * Strategy interface for individual ISP rule checks.
 *
 * Each implementation is responsible for checking a single aspect of the
 * Interface Segregation Principle (e.g. empty/stub methods, fat interfaces).
 */
interface IspRuleCheckerInterface
{
    /**
     * Check a class against one of its interfaces for ISP violations.
     *
     * @return IspViolation[] List of violations found (empty if none)
     * @throws \LogicException Implementations that use parsing or external libraries (e.g. PhpParser) may throw.
     */
    public function check(ReflectionClass $class, ReflectionClass $interface): array;
}
