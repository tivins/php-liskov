<?php

declare(strict_types=1);

// ============================================================================
// ISP Example 1: Empty method implementation (stub)
// An interface with 3 methods, but the implementation only needs 2.
// The third method has an empty body → ISP violation.
// ============================================================================

interface IspWorkerInterface
{
    public function work(): void;
    public function eat(): void;
    public function sleep(): void;
}

class IspHumanWorker implements IspWorkerInterface
{
    public function work(): void
    {
        // Real implementation
        echo "Working...\n";
    }

    public function eat(): void
    {
        // Real implementation
        echo "Eating...\n";
    }

    public function sleep(): void
    {
        // Real implementation
        echo "Sleeping...\n";
    }
}

/**
 * Robot doesn't need to eat or sleep → empty methods = ISP violation.
 */
class IspRobotWorker implements IspWorkerInterface
{
    public function work(): void
    {
        echo "Working...\n";
    }

    public function eat(): void
    {
        // Empty — robot doesn't eat → ISP violation
    }

    public function sleep(): void
    {
        // Empty — robot doesn't sleep → ISP violation
    }
}

// ============================================================================
// ISP Example 2: Throws BadMethodCallException (stub marker)
// ============================================================================

interface IspPrinterInterface
{
    public function printDocument(): void;
    public function scanDocument(): void;
    public function faxDocument(): void;
}

/**
 * Simple printer can only print — scan and fax throw "not implemented" exceptions.
 */
class IspSimplePrinter implements IspPrinterInterface
{
    public function printDocument(): void
    {
        echo "Printing...\n";
    }

    public function scanDocument(): void
    {
        throw new \BadMethodCallException('Simple printer cannot scan.');
    }

    public function faxDocument(): void
    {
        throw new \BadMethodCallException('Simple printer cannot fax.');
    }
}

// ============================================================================
// ISP Example 3: Returns null (stub marker)
// ============================================================================

interface IspRepositoryInterface
{
    public function find(int $id): ?object;
    public function save(object $entity): void;
    public function delete(int $id): void;
}

/**
 * ReadOnlyRepository should not support save/delete.
 */
class IspReadOnlyRepository implements IspRepositoryInterface
{
    public function find(int $id): ?object
    {
        return new \stdClass(); // Real implementation
    }

    public function save(object $entity): void
    {
        return; // Stub — returns void/nothing → ISP violation
    }

    public function delete(int $id): void
    {
        return; // Stub — returns void/nothing → ISP violation
    }
}

// ============================================================================
// ISP Example 4: Fat interface (many methods)
// ============================================================================

interface IspFatInterface
{
    public function method1(): void;
    public function method2(): void;
    public function method3(): void;
    public function method4(): void;
    public function method5(): void;
    public function method6(): void;
}

class IspFatImplementation implements IspFatInterface
{
    public function method1(): void {}
    public function method2(): void {}
    public function method3(): void {}
    public function method4(): void {}
    public function method5(): void {}
    public function method6(): void {}
}

// ============================================================================
// ISP Example 5: Compliant — small interface, all methods implemented properly
// ============================================================================

interface IspCompliantInterface
{
    public function doSomething(): string;
    public function doSomethingElse(): int;
}

class IspCompliantClass implements IspCompliantInterface
{
    public function doSomething(): string
    {
        return 'done';
    }

    public function doSomethingElse(): int
    {
        return 42;
    }
}
