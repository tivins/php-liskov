<?php

declare(strict_types=1);

namespace Tivins\Solid\ISP;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

/**
 * Detects "dead" interface method implementations: methods whose body is empty,
 * only throws a "not implemented" exception, or only returns null/void.
 *
 * These are classic signs that the interface forces a contract that is too wide
 * for this particular implementation.
 */
class EmptyMethodRuleChecker implements IspRuleCheckerInterface
{
    /**
     * Exception classes considered as "not implemented" markers.
     * Only BadMethodCallException (and subclasses) are considered — it is the
     * canonical way to signal "this operation is not supported" in PHP.
     * More generic exceptions like RuntimeException or LogicException are NOT
     * included because they are commonly used in real implementations.
     *
     * @var list<string>
     */
    private const NOT_IMPLEMENTED_EXCEPTIONS = [
        'BadMethodCallException',
    ];

    /** @var array<string, Stmt\ClassLike|null> */
    private array $astCache = [];

    public function check(ReflectionClass $class, ReflectionClass $interface): array
    {
        $violations = [];

        foreach ($interface->getMethods() as $interfaceMethod) {
            if (!$class->hasMethod($interfaceMethod->getName())) {
                continue;
            }

            $classMethod = $class->getMethod($interfaceMethod->getName());

            // Skip methods not actually defined by this class (inherited from parent)
            if ($classMethod->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            // Skip abstract methods
            if ($classMethod->isAbstract()) {
                continue;
            }

            $stubType = $this->detectStubMethod($classMethod);
            if ($stubType !== null) {
                $violations[] = new IspViolation(
                    className: $class->getName(),
                    interfaceName: $interface->getName(),
                    reason: sprintf(
                        'Method %s() is %s — interface may be too wide for this class.',
                        $classMethod->getName(),
                        $stubType,
                    ),
                );
            }
        }

        return $violations;
    }

    /**
     * Detect if a method is a stub (empty body, throws "not implemented", returns null/void).
     *
     * @return string|null Description of the stub type, or null if the method is a real implementation.
     */
    private function detectStubMethod(ReflectionMethod $method): ?string
    {
        $stmts = $this->getMethodAstStatements($method);
        if ($stmts === null) {
            return null; // Cannot parse — assume it's a real implementation
        }

        // Filter out Nop nodes (comment-only lines) — they are not real statements
        $stmts = array_values(array_filter(
            $stmts,
            fn(Node $s) => !($s instanceof Stmt\Nop),
        ));

        // Empty body: no statements at all (or only comments)
        if (count($stmts) === 0) {
            return 'empty (no statements)';
        }

        // Single statement analysis
        if (count($stmts) === 1) {
            $stmt = $stmts[0];

            // throw new \BadMethodCallException(...) or similar
            if ($stmt instanceof Stmt\Expression
                && $stmt->expr instanceof Node\Expr\Throw_
                && $stmt->expr->expr instanceof Node\Expr\New_
            ) {
                $thrownClass = $this->resolveClassName($stmt->expr->expr->class);
                if ($thrownClass !== null && $this->isNotImplementedException($thrownClass)) {
                    return sprintf('a stub (throws %s)', $this->shortName($thrownClass));
                }
            }

            // Standalone throw statement (PHP 8+)
            if ($stmt instanceof Stmt\Throw_
                && $stmt->expr instanceof Node\Expr\New_
            ) {
                $thrownClass = $this->resolveClassName($stmt->expr->class);
                if ($thrownClass !== null && $this->isNotImplementedException($thrownClass)) {
                    return sprintf('a stub (throws %s)', $this->shortName($thrownClass));
                }
            }

            // return null; or return;
            if ($stmt instanceof Stmt\Return_) {
                if ($stmt->expr === null) {
                    return 'a stub (returns void/nothing)';
                }
                if ($stmt->expr instanceof Node\Expr\ConstFetch
                    && strtolower($stmt->expr->name->toString()) === 'null'
                ) {
                    return 'a stub (returns null)';
                }
            }
        }

        return null;
    }

    /**
     * Check if a class name is one of the "not implemented" exception markers.
     */
    private function isNotImplementedException(string $className): bool
    {
        $shortName = $this->shortName($className);
        foreach (self::NOT_IMPLEMENTED_EXCEPTIONS as $exception) {
            if (strcasecmp($shortName, $exception) === 0) {
                return true;
            }
        }

        // Also check if the thrown class is a subclass of any marker
        if (class_exists($className)) {
            foreach (self::NOT_IMPLEMENTED_EXCEPTIONS as $exception) {
                $fqcn = '\\' . $exception;
                if (is_a($className, $fqcn, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the short class name from a FQCN.
     */
    private function shortName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Resolve a class name node to a string.
     */
    private function resolveClassName(Node $node): ?string
    {
        if ($node instanceof Node\Name\FullyQualified) {
            return $node->toString();
        }
        if ($node instanceof Node\Name) {
            return $node->toString();
        }
        return null;
    }

    /**
     * Parse the method's file and extract AST statements for the given method.
     *
     * @return Stmt[]|null The method body statements, or null if parsing failed.
     */
    private function getMethodAstStatements(ReflectionMethod $method): ?array
    {
        $declaringClass = $method->getDeclaringClass();
        $fileName = $declaringClass->getFileName();
        if ($fileName === false) {
            return null;
        }

        $classNode = $this->getClassAstNode($fileName, $declaringClass->getName());
        if ($classNode === null) {
            return null;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $method->getName()) {
                return $stmt->stmts ?? [];
            }
        }

        return null;
    }

    /**
     * Parse a file and cache the ClassLike AST node for the given class name.
     */
    private function getClassAstNode(string $fileName, string $className): ?Stmt\ClassLike
    {
        $cacheKey = $fileName . '::' . $className;
        if (array_key_exists($cacheKey, $this->astCache)) {
            return $this->astCache[$cacheKey];
        }

        $code = file_get_contents($fileName);
        if ($code === false) {
            $this->astCache[$cacheKey] = null;
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            $this->astCache[$cacheKey] = null;
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $collector = new class ($className) extends NodeVisitorAbstract {
            public ?Stmt\ClassLike $found = null;

            public function __construct(private readonly string $targetClass)
            {
            }

            public function enterNode(Node $node): null
            {
                if (($node instanceof Stmt\Class_ || $node instanceof Stmt\Trait_)
                    && $node->namespacedName !== null
                    && $node->namespacedName->toString() === $this->targetClass
                ) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser->addVisitor($collector);

        try {
            $traverser->traverse($stmts);
        } catch (\LogicException $e) {
            // PhpParser NodeTraverser may throw LogicException on edge cases (e.g. ensureReplacementReasonable).
            // Treat as parse failure so check() still respects IspRuleCheckerInterface (no throws).
            $this->astCache[$cacheKey] = null;
            return null;
        }

        $this->astCache[$cacheKey] = $collector->found;
        return $collector->found;
    }
}
