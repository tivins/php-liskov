<?php

declare(strict_types=1);

namespace Tivins\LSP;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Checks if a class violates the Liskov Substitution Principle
 * with respect to its interfaces and parent class.
 *
 * Currently checks:
 * - Exception contract violations via docblock (@throws not declared in parent/interface)
 * - Exception contract violations via AST (actual throw statements not allowed by contract)
 *
 * @todo Check parameter type contravariance (preconditions)
 * @todo Check return type covariance (postconditions)
 */
readonly class LiskovSubstitutionPrincipleChecker
{
    public function __construct(private ThrowsDetector $throwsDetector)
    {
    }

    /**
     * Check a class for LSP violations against all its contracts (interfaces + parent class).
     *
     * @return LspViolation[] List of violations found (empty if none)
     * @throws ReflectionException
     */
    public function check(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $violations = [];

        // Check against all implemented interfaces
        foreach ($reflection->getInterfaces() as $interface) {
            $violations = array_merge(
                $violations,
                $this->checkAgainstContract($reflection, $interface)
            );
        }

        // Check against parent class (if any)
        $parentClass = $reflection->getParentClass();
        if ($parentClass !== false) {
            $violations = array_merge(
                $violations,
                $this->checkAgainstContract($reflection, $parentClass)
            );
        }

        return $violations;
    }

    /**
     * Compare all methods of a class against a contract (interface or parent class).
     *
     * @return LspViolation[]
     */
    private function checkAgainstContract(ReflectionClass $class, ReflectionClass $contract): array
    {
        $violations = [];

        foreach ($contract->getMethods() as $contractMethod) {
            // Only check methods that the class defines itself (not inherited as-is)
            if (!$class->hasMethod($contractMethod->getName())) {
                continue;
            }

            $classMethod = $class->getMethod($contractMethod->getName());

            // Skip if the class method is the same as the contract method (inherited, not overridden)
            if ($classMethod->getDeclaringClass()->getName() === $contract->getName()) {
                continue;
            }

            $violations = array_merge(
                $violations,
                $this->checkThrowsViolations($class, $classMethod, $contract, $contractMethod)
            );
        }

        return $violations;
    }

    /**
     * Check if a class method declares or actually throws exceptions not allowed by the contract.
     *
     * Two types of violations are detected:
     * - Docblock violations: @throws declarations not present in the contract
     * - Code violations: actual throw statements (AST) for exceptions not in the contract
     *
     * @return LspViolation[]
     */
    private function checkThrowsViolations(
        ReflectionClass  $class,
        ReflectionMethod $classMethod,
        ReflectionClass  $contract,
        ReflectionMethod $contractMethod,
    ): array {
        $violations = [];

        $contractThrows = $this->throwsDetector->getDeclaredThrows($contractMethod);
        $classThrowsDeclared = $this->throwsDetector->getDeclaredThrows($classMethod);
        $classThrowsActual = $this->throwsDetector->getActualThrows($classMethod);

        // Violation if the class DECLARES throws not present in the contract
        $unexpectedDeclared = array_diff($classThrowsDeclared, $contractThrows);
        foreach ($unexpectedDeclared as $exceptionType) {
            $violations[] = new LspViolation(
                className: $class->getName(),
                methodName: $classMethod->getName(),
                contractName: $contract->getName(),
                reason: sprintf(
                    '@throws %s declared in docblock but not allowed by the contract',
                    $exceptionType,
                ),
            );
        }

        // Violation if the class ACTUALLY throws exceptions not present in the contract
        $unexpectedActual = array_diff($classThrowsActual, $contractThrows);
        foreach ($unexpectedActual as $exceptionType) {
            $violations[] = new LspViolation(
                className: $class->getName(),
                methodName: $classMethod->getName(),
                contractName: $contract->getName(),
                reason: sprintf(
                    'throws %s in code (detected via AST) but not allowed by the contract',
                    $exceptionType,
                ),
            );
        }

        return $violations;
    }
}