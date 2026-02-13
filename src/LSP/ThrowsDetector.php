<?php

declare(strict_types=1);

namespace Tivins\LSP;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

class ThrowsDetector
{
    /** @var array<string, Stmt[]> Cache of parsed ASTs keyed by file path */
    private array $astCache = [];

    /** @var array<string, array<string, string>> Cache of use imports keyed by class name */
    private array $useImportsCache = [];

    /**
     * Retourne la liste des exceptions déclarées dans le "@throws" du docblock.
     *
     * Formats supportés :
     * - "@throws RuntimeException"
     * - "@throws RuntimeException|InvalidArgumentException"
     * - "@throws \RuntimeException" (FQCN)
     * - "@throws RuntimeException Description text"
     *
     * @return string[] Noms des classes d'exception (normalisés sans le \ initial)
     */
    public function getDeclaredThrows(ReflectionMethod $method): array
    {
        $docblock = $method->getDocComment();
        if ($docblock === false) {
            return [];
        }

        $throws = [];

        // Match only proper @throws tags (at the start of a docblock line, after * )
        if (preg_match_all('/^\s*\*\s*@throws\s+([^\s*]+)/m', $docblock, $matches)) {
            foreach ($matches[1] as $throwsDeclaration) {
                // Handle piped exception types: RuntimeException|InvalidArgumentException
                $types = explode('|', $throwsDeclaration);
                foreach ($types as $type) {
                    $type = ltrim(trim($type), '\\');
                    if ($type !== '') {
                        $throws[] = $type;
                    }
                }
            }
        }

        return array_unique($throws);
    }

    /**
     * Extract the use import map for the file and namespace containing the given class.
     *
     * Returns a map of short alias → FQCN (without leading \).
     * For example, `use Foo\Bar\BazException;` produces ['BazException' => 'Foo\Bar\BazException'].
     * Aliased imports like `use Foo\Bar as Baz;` produce ['Baz' => 'Foo\Bar'].
     *
     * @return array<string, string> short name → FQCN (without leading \)
     */
    public function getUseImportsForClass(ReflectionClass $class): array
    {
        $className = $class->getName();
        if (isset($this->useImportsCache[$className])) {
            return $this->useImportsCache[$className];
        }

        $filename = $class->getFileName();
        if ($filename === false) {
            return $this->useImportsCache[$className] = [];
        }

        $stmts = $this->parseFile($filename);
        if ($stmts === null) {
            return $this->useImportsCache[$className] = [];
        }

        $namespace = $class->getNamespaceName();
        return $this->useImportsCache[$className] = $this->extractUseImports($stmts, $namespace);
    }

    /**
     * Extract use imports from the AST for the given namespace scope.
     *
     * Handles both bracketed (`namespace Foo { use ...; }`) and non-bracketed
     * (`namespace Foo; use ...;`) syntax, as well as files without namespaces.
     *
     * @param Stmt[] $stmts
     * @return array<string, string> short name → FQCN (without leading \)
     */
    private function extractUseImports(array $stmts, string $namespace): array
    {
        $imports = [];

        foreach ($stmts as $stmt) {
            // Namespace block: look inside the matching one
            if ($stmt instanceof Stmt\Namespace_) {
                $nsName = $stmt->name ? $stmt->name->toString() : '';
                if ($nsName === $namespace) {
                    foreach ($stmt->stmts as $nsStmt) {
                        if ($nsStmt instanceof Stmt\Use_ && $nsStmt->type === Stmt\Use_::TYPE_NORMAL) {
                            foreach ($nsStmt->uses as $use) {
                                $fqcn = $use->name->toString();
                                $alias = $use->getAlias()->toString();
                                $imports[$alias] = $fqcn;
                            }
                        }
                    }
                    return $imports;
                }
                continue;
            }

            // Top-level use (file without namespace)
            if ($namespace === '' && $stmt instanceof Stmt\Use_ && $stmt->type === Stmt\Use_::TYPE_NORMAL) {
                foreach ($stmt->uses as $use) {
                    $fqcn = $use->name->toString();
                    $alias = $use->getAlias()->toString();
                    $imports[$alias] = $fqcn;
                }
            }
        }

        return $imports;
    }

    /**
     * Détecte les exceptions réellement lancées dans le corps de la méthode
     * via analyse AST (nikic/php-parser).
     *
     * Suit récursivement les appels internes ($this->method()) au sein de la même classe,
     * les appels statiques cross-classe (ClassName::method()) et les appels sur instances
     * créées localement ((new ClassName())->method()).
     * Gère les cas suivants :
     * - throw new Exception() (direct)
     * - throw conditionnel (if/else)
     * - re-throw dans un catch (catch (E $e) { throw $e; })
     * - throw transitif via $this->privateMethod()
     * - throw transitif via ClassName::staticMethod() (cross-class)
     * - throw transitif via (new ClassName())->method() (instance cross-class)
     *
     * @return string[] Noms des classes d'exception (normalisés sans le \ initial)
     */
    public function getActualThrows(ReflectionMethod $method): array
    {
        $filename = $method->getFileName();
        if ($filename === false) {
            return [];
        }

        $stmts = $this->parseFile($filename);
        if ($stmts === null) {
            return [];
        }

        $methodNode = $this->findMethodNode($stmts, $method->getName(), $method->getStartLine(), $method->getEndLine());
        if ($methodNode === null) {
            return [];
        }

        // Find the enclosing class to resolve $this->method() calls
        $classNode = $this->findEnclosingClass($stmts, $method->getStartLine(), $method->getEndLine());

        // Track visited methods to prevent infinite recursion (circular calls)
        $visited = [];

        return $this->extractThrowTypesRecursive($methodNode, $classNode, $visited);
    }

    /**
     * Parse a PHP file and return its AST with resolved names.
     * Uses an internal cache to avoid re-parsing the same file.
     *
     * @return Stmt[]|null
     */
    private function parseFile(string $filename): ?array
    {
        if (isset($this->astCache[$filename])) {
            return $this->astCache[$filename];
        }

        $code = file_get_contents($filename);
        if ($code === false) {
            return null;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            return null;
        }

        // Resolve all names (use statements, namespaces) for FQCN resolution
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $this->astCache[$filename] = $stmts;
        return $stmts;
    }

    /**
     * Find the ClassMethod node matching the given method name and line range.
     */
    private function findMethodNode(array $stmts, string $methodName, int $startLine, int $endLine): ?Stmt\ClassMethod
    {
        $found = null;

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($methodName, $startLine, $endLine, $found) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string         $methodName,
                private readonly int            $startLine,
                private readonly int            $endLine,
                private ?Stmt\ClassMethod       &$found,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    $node instanceof Stmt\ClassMethod
                    && $node->name->toString() === $this->methodName
                    && $node->getStartLine() === $this->startLine
                    && $node->getEndLine() === $this->endLine
                ) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Find the Class_/Enum_ node that contains the given line range.
     *
     * @return Stmt\Class_|Stmt\Enum_|null
     */
    private function findEnclosingClass(array $stmts, int $startLine, int $endLine): ?Stmt
    {
        $found = null;

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($startLine, $endLine, $found) extends NodeVisitorAbstract {
            public function __construct(
                private readonly int $startLine,
                private readonly int $endLine,
                private ?Stmt       &$found,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_)
                    && $node->getStartLine() <= $this->startLine
                    && $node->getEndLine() >= $this->endLine
                ) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        });
        $traverser->traverse($stmts);

        return $found;
    }

    /**
     * Find a ClassMethod by name within a class node.
     */
    private function findMethodInClass(Stmt $classNode, string $methodName): ?Stmt\ClassMethod
    {
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                return $stmt;
            }
        }
        return null;
    }

    /**
     * Extract all exception types thrown within a method node, following
     * $this->method() calls recursively within the same class,
     * ClassName::method() static calls and (new ClassName())->method() instance calls across class boundaries.
     *
     * Handles:
     * - throw new ClassName() → extracts ClassName
     * - catch (ExType $e) { ... throw $e; } → extracts ExType from the catch clause
     * - $this->otherMethod() → recursively analyses otherMethod in the same class
     * - ClassName::staticMethod() → recursively analyses the static method in the external class
     * - (new ClassName())->method() → recursively analyses the instance method in the external class
     *
     * @param Stmt\ClassMethod             $methodNode The method to analyze
     * @param Stmt\Class_|Stmt\Enum_|null  $classNode  The enclosing class (for resolving $this-> calls)
     * @param array<string, true>          $visited    Set of already-visited method names (prevents infinite recursion)
     * @return string[] Unique exception class names (without leading \)
     */
    private function extractThrowTypesRecursive(
        Stmt\ClassMethod $methodNode,
        ?Stmt            $classNode,
        array            &$visited,
    ): array {
        $methodName = $methodNode->name->toString();

        // Build a class-qualified key to prevent infinite recursion across classes
        $classId = ($classNode !== null && isset($classNode->namespacedName))
            ? $classNode->namespacedName->toString()
            : '';
        $visitedKey = $classId . '::' . $methodName;

        // Guard against circular calls (A::x -> B::y -> A::x)
        if (isset($visited[$visitedKey])) {
            return [];
        }
        $visited[$visitedKey] = true;

        $throws = [];
        $internalCalls = [];
        $externalCalls = [];
        $instanceCalls = [];

        // Build a map of catch variable names → their caught exception types
        // so we can resolve re-throws like `throw $e;`
        $catchVariableTypes = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($throws, $catchVariableTypes, $internalCalls, $externalCalls, $instanceCalls) extends NodeVisitorAbstract {
            public function __construct(
                private array &$throws,
                private array &$catchVariableTypes,
                private array &$internalCalls,
                private array &$externalCalls,
                private array &$instanceCalls,
            ) {
            }

            public function enterNode(Node $node): null
            {
                // Register catch variable types for re-throw resolution
                if ($node instanceof Stmt\Catch_) {
                    if ($node->var !== null) {
                        $varName = $node->var->name;
                        foreach ($node->types as $type) {
                            $this->catchVariableTypes[$varName][] = $type->toString();
                        }
                    }
                }

                // Detect $this->methodName() calls for recursive analysis
                if (
                    $node instanceof Expr\MethodCall
                    && $node->var instanceof Expr\Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier
                ) {
                    $this->internalCalls[] = $node->name->toString();
                }

                // Detect (new ClassName())->methodName() instance calls for cross-class analysis
                if (
                    $node instanceof Expr\MethodCall
                    && $node->var instanceof Expr\New_
                    && $node->var->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                ) {
                    $this->instanceCalls[] = [$node->var->class->toString(), $node->name->toString()];
                }

                // Detect ClassName::methodName() static calls for cross-class analysis
                if (
                    $node instanceof Expr\StaticCall
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                ) {
                    $this->externalCalls[] = [$node->class->toString(), $node->name->toString()];
                }

                // Detect throw statements (Stmt\Throw_ in php-parser v5 for `throw expr;`)
                // In PHP 8+, throw is also an expression (Expr\Throw_)
                $throwExpr = null;
                if ($node instanceof Stmt\Expression && $node->expr instanceof Expr\Throw_) {
                    $throwExpr = $node->expr->expr;
                } elseif ($node instanceof Stmt\Throw_) {
                    $throwExpr = $node->expr;
                } elseif ($node instanceof Expr\Throw_) {
                    $throwExpr = $node->expr;
                }

                if ($throwExpr !== null) {
                    // Case 1: throw new ClassName(...)
                    if ($throwExpr instanceof Expr\New_ && $throwExpr->class instanceof Node\Name) {
                        $this->throws[] = $throwExpr->class->toString();
                    }
                    // Case 2: throw $variable (re-throw from catch)
                    elseif ($throwExpr instanceof Expr\Variable && is_string($throwExpr->name)) {
                        $varName = $throwExpr->name;
                        if (isset($this->catchVariableTypes[$varName])) {
                            foreach ($this->catchVariableTypes[$varName] as $type) {
                                $this->throws[] = $type;
                            }
                        }
                    }
                }

                return null;
            }
        });
        $traverser->traverse([$methodNode]);

        // Recursively follow $this->method() calls within the same class
        if ($classNode !== null) {
            foreach (array_unique($internalCalls) as $calledMethodName) {
                $calledNode = $this->findMethodInClass($classNode, $calledMethodName);
                if ($calledNode !== null) {
                    $throws = array_merge(
                        $throws,
                        $this->extractThrowTypesRecursive($calledNode, $classNode, $visited),
                    );
                }
            }
        }

        // Recursively follow ClassName::methodName() static calls to external classes
        $seenExternal = [];
        foreach ($externalCalls as [$calledClass, $calledMethod]) {
            $externalKey = $calledClass . '::' . $calledMethod;
            if (isset($seenExternal[$externalKey])) {
                continue;
            }
            $seenExternal[$externalKey] = true;

            $calledClassNorm = ltrim($calledClass, '\\');

            // Self-referencing static call → treat as internal call
            if ($classNode !== null && isset($classNode->namespacedName)
                && $calledClassNorm === $classNode->namespacedName->toString()) {
                $calledNode = $this->findMethodInClass($classNode, $calledMethod);
                if ($calledNode !== null) {
                    $throws = array_merge(
                        $throws,
                        $this->extractThrowTypesRecursive($calledNode, $classNode, $visited),
                    );
                }
                continue;
            }

            if (!class_exists($calledClassNorm) && !interface_exists($calledClassNorm)) {
                continue;
            }

            try {
                $refClass = new ReflectionClass($calledClassNorm);
                if (!$refClass->hasMethod($calledMethod)) {
                    continue;
                }
                $refMethod = $refClass->getMethod($calledMethod);
                $filename = $refMethod->getFileName();
                if ($filename === false) {
                    continue;
                }

                $extStmts = $this->parseFile($filename);
                if ($extStmts === null) {
                    continue;
                }

                $extMethodNode = $this->findMethodNode(
                    $extStmts,
                    $calledMethod,
                    $refMethod->getStartLine(),
                    $refMethod->getEndLine(),
                );
                if ($extMethodNode === null) {
                    continue;
                }

                $extClassNode = $this->findEnclosingClass(
                    $extStmts,
                    $refMethod->getStartLine(),
                    $refMethod->getEndLine(),
                );

                $throws = array_merge(
                    $throws,
                    $this->extractThrowTypesRecursive($extMethodNode, $extClassNode, $visited),
                );
            } catch (\ReflectionException) {
                continue;
            }
        }

        // Recursively follow (new ClassName())->methodName() instance calls to external classes
        $seenInstance = [];
        foreach ($instanceCalls as [$calledClass, $calledMethod]) {
            $instanceKey = $calledClass . '::' . $calledMethod;
            if (isset($seenInstance[$instanceKey])) {
                continue;
            }
            $seenInstance[$instanceKey] = true;

            $calledClassNorm = ltrim($calledClass, '\\');

            // Self-referencing instance call (new self() or same class) → treat as internal
            if ($classNode !== null && isset($classNode->namespacedName)
                && $calledClassNorm === $classNode->namespacedName->toString()) {
                $calledNode = $this->findMethodInClass($classNode, $calledMethod);
                if ($calledNode !== null) {
                    $throws = array_merge(
                        $throws,
                        $this->extractThrowTypesRecursive($calledNode, $classNode, $visited),
                    );
                }
                continue;
            }

            if (!class_exists($calledClassNorm) && !interface_exists($calledClassNorm)) {
                continue;
            }

            try {
                $refClass = new ReflectionClass($calledClassNorm);
                if (!$refClass->hasMethod($calledMethod)) {
                    continue;
                }
                $refMethod = $refClass->getMethod($calledMethod);
                $filename = $refMethod->getFileName();
                if ($filename === false) {
                    continue;
                }

                $extStmts = $this->parseFile($filename);
                if ($extStmts === null) {
                    continue;
                }

                $extMethodNode = $this->findMethodNode(
                    $extStmts,
                    $calledMethod,
                    $refMethod->getStartLine(),
                    $refMethod->getEndLine(),
                );
                if ($extMethodNode === null) {
                    continue;
                }

                $extClassNode = $this->findEnclosingClass(
                    $extStmts,
                    $refMethod->getStartLine(),
                    $refMethod->getEndLine(),
                );

                $throws = array_merge(
                    $throws,
                    $this->extractThrowTypesRecursive($extMethodNode, $extClassNode, $visited),
                );
            } catch (\ReflectionException) {
                continue;
            }
        }

        // Normalize: remove leading backslash and deduplicate
        $throws = array_map(fn(string $t) => ltrim($t, '\\'), $throws);

        return array_values(array_unique($throws));
    }
}