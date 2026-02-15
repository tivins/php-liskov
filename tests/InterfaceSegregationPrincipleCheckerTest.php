<?php

declare(strict_types=1);

namespace Tivins\Solid\Tests;

use PHPUnit\Framework\TestCase;
use Tivins\Solid\ISP\EmptyMethodRuleChecker;
use Tivins\Solid\ISP\FatInterfaceRuleChecker;
use Tivins\Solid\ISP\InterfaceSegregationPrincipleChecker;
use Tivins\Solid\ISP\IspViolation;

/**
 * Unit tests for InterfaceSegregationPrincipleChecker using the built-in example classes.
 *
 * Example classes are defined in examples/isp-violation-example.php:
 * - IspHumanWorker: all methods implemented properly → no violation
 * - IspRobotWorker: eat() and sleep() are empty stubs → violation
 * - IspSimplePrinter: scanDocument() and faxDocument() throw "not implemented" → violation
 * - IspReadOnlyRepository: save() and delete() return void/nothing → violation
 * - IspFatImplementation: implements IspFatInterface with 6 methods → fat interface violation
 * - IspCompliantClass: small interface, all methods implemented → no violation
 */
final class InterfaceSegregationPrincipleCheckerTest extends TestCase
{
    private static bool $examplesLoaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$examplesLoaded) {
            require_once __DIR__ . '/../examples/isp-violation-example.php';
            self::$examplesLoaded = true;
        }
    }

    private function createChecker(int $fatThreshold = FatInterfaceRuleChecker::DEFAULT_THRESHOLD): InterfaceSegregationPrincipleChecker
    {
        return new InterfaceSegregationPrincipleChecker([
            new EmptyMethodRuleChecker(),
            new FatInterfaceRuleChecker($fatThreshold),
        ]);
    }

    // ---- Empty method detection ----

    public function testIspHumanWorkerHasNoViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\IspHumanWorker::class);

        $this->assertEmpty($violations, 'IspHumanWorker implements all methods properly — no ISP violation');
    }

    public function testIspRobotWorkerHasEmptyMethodViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\IspRobotWorker::class);

        $this->assertNotEmpty($violations, 'IspRobotWorker has empty eat() and sleep() — ISP violation');
        $this->assertCount(2, $violations, 'Should have exactly 2 violations (eat + sleep)');

        $methodNames = array_map(
            fn(IspViolation $v) => $v->reason,
            $violations,
        );
        $reasons = implode(' ', $methodNames);
        $this->assertStringContainsString('eat()', $reasons);
        $this->assertStringContainsString('sleep()', $reasons);
        $this->assertStringContainsString('empty', $reasons);
    }

    // ---- "Not implemented" exception detection ----

    public function testIspSimplePrinterHasStubViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\IspSimplePrinter::class);

        $this->assertNotEmpty($violations, 'IspSimplePrinter has stub methods that throw exceptions — ISP violation');
        $this->assertCount(2, $violations, 'Should have exactly 2 violations (scanDocument + faxDocument)');

        $reasons = implode(' ', array_map(fn(IspViolation $v) => $v->reason, $violations));
        $this->assertStringContainsString('scanDocument()', $reasons);
        $this->assertStringContainsString('faxDocument()', $reasons);
        $this->assertStringContainsString('stub', $reasons);
    }

    // ---- Return null/void detection ----

    public function testIspReadOnlyRepositoryHasReturnNullViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\IspReadOnlyRepository::class);

        $this->assertNotEmpty($violations, 'IspReadOnlyRepository has void-return stubs — ISP violation');
        $this->assertCount(2, $violations, 'Should have exactly 2 violations (save + delete)');

        $reasons = implode(' ', array_map(fn(IspViolation $v) => $v->reason, $violations));
        $this->assertStringContainsString('save()', $reasons);
        $this->assertStringContainsString('delete()', $reasons);
        $this->assertStringContainsString('stub', $reasons);
    }

    // ---- Fat interface detection ----

    public function testIspFatInterfaceDetected(): void
    {
        $checker = $this->createChecker(fatThreshold: 5);
        $violations = $checker->check(\IspFatImplementation::class);

        // Fat interface (6 methods > threshold 5) + 6 empty methods
        $fatViolations = array_filter(
            $violations,
            fn(IspViolation $v) => str_contains($v->reason, 'methods (threshold'),
        );
        $this->assertCount(1, $fatViolations, 'Should detect 1 fat interface violation');

        $fatViolation = reset($fatViolations);
        $this->assertStringContainsString('6 methods', $fatViolation->reason);
        $this->assertStringContainsString('IspFatInterface', $fatViolation->interfaceName);
    }

    public function testIspFatInterfaceNotDetectedBelowThreshold(): void
    {
        $checker = $this->createChecker(fatThreshold: 10);
        $violations = $checker->check(\IspFatImplementation::class);

        // Only empty method violations, no fat interface violation
        $fatViolations = array_filter(
            $violations,
            fn(IspViolation $v) => str_contains($v->reason, 'methods (threshold'),
        );
        $this->assertEmpty($fatViolations, 'Should not detect fat interface when threshold is 10');
    }

    // ---- Compliant class ----

    public function testIspCompliantClassHasNoViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\IspCompliantClass::class);

        $this->assertEmpty($violations, 'IspCompliantClass implements all methods properly — no ISP violation');
    }

    // ---- IspViolation value object ----

    public function testIspViolationToString(): void
    {
        $violation = new IspViolation(
            className: 'MyClass',
            interfaceName: 'MyInterface',
            reason: 'Method foo() is empty',
        );
        $this->assertStringContainsString('MyClass', (string) $violation);
        $this->assertStringContainsString('MyInterface', (string) $violation);
        $this->assertStringContainsString('Method foo() is empty', (string) $violation);
    }

    public function testIspViolationToStringWithDetails(): void
    {
        $violation = new IspViolation(
            className: 'MyClass',
            interfaceName: 'MyInterface',
            reason: 'Fat interface',
            details: "Methods: foo, bar, baz",
        );
        $str = (string) $violation;
        $this->assertStringContainsString('Methods: foo, bar, baz', $str);
    }

    public function testAllExampleClassesAreCheckedWithoutReflectionException(): void
    {
        $classes = [
            \IspHumanWorker::class,
            \IspRobotWorker::class,
            \IspSimplePrinter::class,
            \IspReadOnlyRepository::class,
            \IspFatImplementation::class,
            \IspCompliantClass::class,
        ];
        $checker = $this->createChecker();

        foreach ($classes as $className) {
            $violations = $checker->check($className);
            $this->assertIsArray($violations);
            foreach ($violations as $v) {
                $this->assertInstanceOf(IspViolation::class, $v);
            }
        }
    }
}
