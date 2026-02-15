<?php

declare(strict_types=1);

namespace Tivins\Solid\ISP;

use ReflectionClass;
use ReflectionException;

/**
 * Checks if a class violates the Interface Segregation Principle
 * with respect to its interfaces.
 *
 * This class acts as an orchestrator: it resolves interfaces,
 * and delegates each check to the registered rule checkers (Strategy pattern).
 *
 * Currently supported rules (via IspRuleCheckerInterface implementations):
 * - Empty/stub method detection (EmptyMethodRuleChecker)
 * - Fat interface detection (FatInterfaceRuleChecker)
 */
readonly class InterfaceSegregationPrincipleChecker
{
    /**
     * @param IspRuleCheckerInterface[] $ruleCheckers
     */
    public function __construct(private array $ruleCheckers)
    {
    }

    /**
     * Check a class for ISP violations against all its interfaces.
     *
     * @return IspViolation[] List of violations found (empty if none)
     * @throws ReflectionException
     */
    public function check(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $violations = [];

        // ISP applies to interfaces only (not parent classes)
        foreach ($reflection->getInterfaces() as $interface) {
            foreach ($this->ruleCheckers as $ruleChecker) {
                $violations = array_merge(
                    $violations,
                    $ruleChecker->check($reflection, $interface)
                );
            }
        }

        return $violations;
    }
}
