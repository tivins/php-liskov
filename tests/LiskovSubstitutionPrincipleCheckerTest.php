<?php

declare(strict_types=1);

namespace Tivins\LSP\Tests;

use PHPUnit\Framework\TestCase;
use Tivins\LSP\LiskovSubstitutionPrincipleChecker;
use Tivins\LSP\LspViolation;
use Tivins\LSP\ThrowsDetector;

/**
 * Unit tests for LiskovSubstitutionPrincipleChecker using the built-in example classes.
 *
 * Example classes are defined in liskov-principles-violation-example.php:
 * - MyClass1: interface has no @throws, implementation throws → violation
 * - MyClass2: interface has @throws RuntimeException, implementation throws → no violation
 * - MyClass3: interface has no @throws, implementation (via private) throws → violation
 * - MyClass4: interface has no @throws, code throws (no @throws docblock) → AST violation
 * - MyClass5: interface has no @throws, code throws via private method → AST violation
 * - MyClass2b: interface has @throws RuntimeException, implementation throws UnexpectedValueException (subclass) → no violation (exception hierarchy)
 */
final class LiskovSubstitutionPrincipleCheckerTest extends TestCase
{
    private static bool $examplesLoaded = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$examplesLoaded) {
            require_once __DIR__ . '/../liskov-principles-violation-example.php';
            self::$examplesLoaded = true;
        }
    }

    private function createChecker(): LiskovSubstitutionPrincipleChecker
    {
        return new LiskovSubstitutionPrincipleChecker(new ThrowsDetector());
    }

    public function testMyClass1HasViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass1::class);

        $this->assertNotEmpty($violations, 'MyClass1 should violate LSP (throws RuntimeException, contract has no @throws)');
        $reasons = array_map(fn(LspViolation $v) => $v->reason, $violations);
        $this->assertStringContainsString('RuntimeException', implode(' ', $reasons));
    }

    public function testMyClass2HasNoViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass2::class);

        $this->assertEmpty($violations, 'MyClass2 should not violate LSP (interface documents @throws RuntimeException)');
    }

    public function testMyClass2bHasNoViolationsWhenThrowingSubclassOfContractException(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass2b::class);

        $this->assertEmpty($violations, 'MyClass2b should not violate LSP (contract allows RuntimeException, implementation throws UnexpectedValueException which is a subclass)');
    }

    public function testMyClass3HasViolations(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass3::class);

        $this->assertNotEmpty($violations, 'MyClass3 should violate LSP (private method throws, contract has no @throws)');
        $reasons = implode(' ', array_map(fn(LspViolation $v) => $v->reason, $violations));
        $this->assertStringContainsString('RuntimeException', $reasons);
        $this->assertStringContainsString('InvalidArgumentException', $reasons);
    }

    public function testMyClass4HasViolationsFromAst(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass4::class);

        $this->assertNotEmpty($violations, 'MyClass4 should violate LSP (code throws, no @throws in contract; AST detection)');
        $reasons = implode(' ', array_map(fn(LspViolation $v) => $v->reason, $violations));
        $this->assertStringContainsString('RuntimeException', $reasons);
        $this->assertStringContainsString('AST', $reasons);
    }

    public function testMyClass5HasViolationsFromAst(): void
    {
        $checker = $this->createChecker();
        $violations = $checker->check(\MyClass5::class);

        $this->assertNotEmpty($violations, 'MyClass5 should violate LSP (private method throws, AST detection)');
        $reasons = implode(' ', array_map(fn(LspViolation $v) => $v->reason, $violations));
        $this->assertStringContainsString('RuntimeException', $reasons);
        $this->assertStringContainsString('AST', $reasons);
    }

    public function testAllExampleClassesAreCheckedWithoutReflectionException(): void
    {
        $classes = [\MyClass1::class, \MyClass2::class, \MyClass2b::class, \MyClass3::class, \MyClass4::class, \MyClass5::class];
        $checker = $this->createChecker();

        foreach ($classes as $className) {
            $violations = $checker->check($className);
            $this->assertIsArray($violations);
            foreach ($violations as $v) {
                $this->assertInstanceOf(LspViolation::class, $v);
            }
        }
    }
}
