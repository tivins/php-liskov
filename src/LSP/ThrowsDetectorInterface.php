<?php

declare(strict_types=1);

namespace Tivins\LSP;

use ReflectionClass;
use ReflectionMethod;

interface ThrowsDetectorInterface
{
    /**
     * Returns the list of exception types declared in the docblock's "@throws" tags.
     *
     * Supported formats:
     * - "@throws RuntimeException"
     * - "@throws RuntimeException|InvalidArgumentException"
     * - "@throws \RuntimeException" (FQCN)
     * - "@throws RuntimeException Description text"
     *
     * @return string[] Exception class names (normalized without leading \)
     */
    public function getDeclaredThrows(ReflectionMethod $method): array;

    /**
     * Extract the use import map for the file and namespace containing the given class.
     *
     * Returns a map of short alias → FQCN (without leading \).
     * For example, `use Foo\Bar\BazException;` produces ['BazException' => 'Foo\Bar\BazException'].
     * Aliased imports like `use Foo\Bar as Baz;` produce ['Baz' => 'Foo\Bar'].
     *
     * @return array<string, string> short name → FQCN (without leading \)
     */
    public function getUseImportsForClass(ReflectionClass $class): array;

    /**
     * Detects exceptions actually thrown in the method body via AST analysis (nikic/php-parser).
     *
     * Recursively follows internal calls ($this->method()) within the same class,
     * cross-class static calls (ClassName::method()), and calls on locally created
     * instances ((new ClassName())->method()).
     *
     * @return string[] Exception class names (normalized without leading \)
     */
    public function getActualThrows(ReflectionMethod $method): array;
}
