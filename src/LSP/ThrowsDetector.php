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
use ReflectionMethod;

class ThrowsDetector
{
    /** @var array<string, Stmt[]> Cache of parsed ASTs keyed by file path */
    private array $astCache = [];

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
     * Détecte les exceptions réellement lancées dans le corps de la méthode
     * via analyse AST (nikic/php-parser).
     *
     * Analyse intra-méthode uniquement : ne suit pas les appels internes ($this->helper()).
     * Gère les cas suivants :
     * - throw new Exception() (direct)
     * - throw conditionnel (if/else)
     * - re-throw dans un catch (catch (E $e) { throw $e; })
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

        return $this->extractThrowTypes($methodNode);
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
     * Extract all exception types thrown within a method node.
     *
     * Handles:
     * - throw new ClassName() → extracts ClassName
     * - catch (ExType $e) { ... throw $e; } → extracts ExType from the catch clause
     *
     * @return string[] Unique exception class names (without leading \)
     */
    private function extractThrowTypes(Stmt\ClassMethod $methodNode): array
    {
        $throws = [];

        // Build a map of catch variable names → their caught exception types
        // so we can resolve re-throws like `throw $e;`
        $catchVariableTypes = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($throws, $catchVariableTypes) extends NodeVisitorAbstract {
            public function __construct(
                private array &$throws,
                private array &$catchVariableTypes,
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

        // Normalize: remove leading backslash and deduplicate
        $throws = array_map(fn(string $t) => ltrim($t, '\\'), $throws);

        return array_values(array_unique($throws));
    }
}