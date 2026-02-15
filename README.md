# php-solid - SOLID principles checker

A PHP tool that checks **SOLID** principles in your codebase. It detects **Liskov Substitution Principle (LSP)** and **Interface Segregation Principle (ISP)** violations.

[![CI](https://github.com/tivins/php-solid/actions/workflows/ci.yml/badge.svg)](https://github.com/tivins/php-solid/actions/workflows/ci.yml)

---

## Principles covered

- **LSP (Liskov Substitution Principle)** — Exception contracts, return type covariance, and parameter type contravariance between classes and their contracts (interfaces and parent classes).
- **ISP (Interface Segregation Principle)** — Dead or empty methods, "not implemented" stubs, and fat interfaces (configurable threshold).

---

## LSP — What it checks

A subclass or implementation must not weaken the contract of its parent or interface. The checker verifies:

- A method must not **declare** (in docblocks) or **throw** (in code) exception types that are not allowed by the contract (interface or parent class).
- If the contract says nothing about exceptions, the implementation must not throw (or declare) any.
- If the contract documents `@throws SomeException`, the implementation may throw that type or any **subclass** (e.g. contract `@throws RuntimeException` allows throwing `UnexpectedValueException`).
- A method return type must be **covariant** with the contract return type (same type or more specific subtype).
- A method parameter type must be **contravariant** with the contract parameter type (same type or wider supertype). Narrowing a parameter type strengthens the precondition and is a violation.

Violations are reported as:

1. **Docblock violations** — `@throws` in the implementation that are not in the contract.
2. **Code violations** — actual `throw` statements (detected via AST) for exception types not allowed by the contract.

### LSP example

```php
interface MyInterface1
{
    /**
     * This method does not mention throwing an exception. Subclasses must not throw any exceptions.
     */
    public function doSomething(): void;
}

/**
 * This class violates the Liskov Substitution Principle.
 */
class MyClass1 implements MyInterface1
{
    /**
     * This method throws an exception, which violates the Liskov Substitution Principle.
     */
    public function doSomething(): void
    {
        throw new RuntimeException("exception is thrown");
    }
}
```

### LSP features

- **Docblock analysis** — parses `@throws` from PHPDoc (supports piped types, FQCN, descriptions).
- **AST analysis** — uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to detect real `throw` statements:
  - Direct throws, conditional throws, re-throws in catch.
  - **Transitive throws** — follows `$this->method()` calls within the same class.
  - **Cross-class static/instance calls** — follows `ClassName::method()` and `(new ClassName())->method()`.
  - **Dynamic method calls on variables** — follows `$variable->method()` when the variable type is known (parameter type hints, local assignments). Union types on parameters are supported.
- **Contract comparison** — checks against all implemented interfaces and the parent class.
- **Return type covariance** and **parameter type contravariance** validation.
- **Cached parsing** — each file is parsed once; results are reused for multiple methods.

---

## ISP — What it checks

Clients should not be forced to depend on methods they do not use. The checker detects:

- **Dead or empty methods** — methods with an empty body (or comments only), suggesting the interface is too broad for this class.
- **"Not implemented" stubs** — methods whose body is a single `throw new \BadMethodCallException(...)`, the canonical PHP way to signal an unsupported operation.
- **Return-null/void stubs** — methods that only `return;` or `return null;`, another sign of a forced contract.
- **Fat interfaces** — interfaces with more methods than a configurable threshold (default: 5). Reported once per interface.

### ISP example

```php
interface WorkerInterface
{
    public function work(): void;
    public function eat(): void;
    public function sleep(): void;
}

// Robot doesn't need to eat or sleep → empty methods = ISP violation
class RobotWorker implements WorkerInterface
{
    public function work(): void { echo "Working...\n"; }
    public function eat(): void { /* empty — ISP violation */ }
    public function sleep(): void { /* empty — ISP violation */ }
}
```

### ISP features

- **AST-based body analysis** — uses [nikic/php-parser](https://github.com/nikic/PHP-Parser) to inspect method bodies. Comment-only methods are treated as empty.
- **Configurable threshold** — set the fat interface method threshold with `--isp-threshold <n>` (default: 5).
- **Strategy pattern** — pluggable rule checkers via `IspRuleCheckerInterface`, same architecture as LSP.

---

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
composer require tivins/php-solid
```

## Usage

You can run the checker in two ways: by passing a directory, or by using a configuration file.

### Scan a directory

Pass a directory path as the first argument. The path is relative to the current working directory. The checker builds a config with that directory and recursively finds all PHP classes to check:

```bash
vendor/bin/php-solid src/
```

The classes (and their contracts — interfaces, parent classes) must be loadable. If a `vendor/autoload.php` is found in or near the target directory, it is included automatically.

### Configuration file

Use `--config <file>` to load a PHP file that **returns** a `Tivins\Solid\Config` instance. The config defines which directories and files to scan, optional exclusions, and optional ISP threshold.

```bash
vendor/bin/php-solid --config config.php
```

**Config file:** Copy the bundled example to your project and adapt paths:

- **Example file:** `config-example.php` (in the package root after install, or in this repo).
- **Config class:** `Tivins\Solid\Config`.

Example (e.g. copy `config-example.php` to `config.php`):

```php
<?php

declare(strict_types=1);

use Tivins\Solid\Config;

return (new Config())
    ->addDirectory('path/to/folder')
    ->excludeDirectory('path/to/folder/excluded')
    ->addFile('path/to/file')
    ->excludeFile('path/to/excluded/file');
```

- **`addDirectory($path)`** — Recursively scan a directory for PHP classes.
- **`addFile($path)`** — Include a single PHP file.
- **`excludeDirectory($path)`** — Skip that directory and its contents when scanning.
- **`excludeFile($path)`** — Skip that file even if it would be included by a directory.
- **`setIspThreshold($n)`** — (Optional) Default fat-interface method threshold for this project. The CLI option `--isp-threshold <n>` overrides this when provided.

Paths are resolved relative to the **current working directory** when you run the checker (e.g. when you run `vendor/bin/php-solid --config config.php` from your project root, `addDirectory('src')` refers to `./src`).

Without a directory and without `--config`, the script prints usage and exits:

```bash
vendor/bin/php-solid
# Usage: php-solid <directory> [options]
#        php-solid --config <file> [options]
#   ...
```

### Run unit tests

The example classes in `examples/liskov-violation-example.php` and `examples/isp-violation-example.php` are used by PHPUnit tests:

```bash
composer install
composer test # or vendor/bin/phpunit
```

### Output streams (stdout / stderr)

- **stdout** — Program result only: either human-readable [PASS]/[FAIL] lines (default) or a single JSON report when `--json` is used. Safe to redirect or pipe (e.g. `> out.json`).
- **stderr** — Progress and summary messages ("Checking…", "Classes checked: …", etc.). Suppressed with `--quiet`.

So you can capture only the result in a file and keep logs separate.

### Options

| Option               | Description |
|----------------------|-------------|
| `<directory>`        | Directory to scan. **Required** when not using `--config`. |
| `--config <file>`    | Path to a PHP file that returns a `Tivins\Solid\Config` instance. When present, `<directory>` is not required. |
| `--lsp`              | Run only LSP checks (skip ISP). |
| `--isp`              | Run only ISP checks (skip LSP). |
| `--isp-threshold <n>` | Fat interface method threshold (default: 5). |
| `--quiet`            | Suppress progress and summary on stderr. Only the result (stdout) is produced — useful for CI or when piping. |
| `--json`             | Machine-readable output: write only the JSON report to stdout; no [PASS]/[FAIL] lines. |

When neither `--lsp` nor `--isp` is specified, both principles are checked.

### Pipes and redirections

| Goal | Command |
|------|---------|
| Save JSON report to a file | `vendor/bin/php-solid src/ --json > report.json` |
| Save human result, hide progress | `vendor/bin/php-solid src/ --quiet > result.txt` |
| Save progress/summary to a log | `vendor/bin/php-solid src/ 2> progress.log` (result stays on terminal) |
| JSON only, no progress (e.g. CI) | `vendor/bin/php-solid src/ --json --quiet 2>/dev/null` |
| Result to file, progress to another file | `vendor/bin/php-solid src/ --json > report.json 2> progress.log` |
| Use a config file | `vendor/bin/php-solid --config config.php` |

To pipe the JSON into another tool (e.g. [jq](https://jqlang.github.io/jq/)), use `--json --quiet` so only JSON goes to stdout:

```bash
vendor/bin/php-solid src/ --json --quiet | jq '.violations | length'
```

The JSON report is an object with two keys:
- **`violations`** — array of violations. Each violation has a `principle` key (`"LSP"` or `"ISP"`). LSP violations include `className`, `methodName`, `contractName`, `reason`, `details`. ISP violations include `className`, `interfaceName`, `reason`, `details`.
- **`errors`** — array of load/reflection errors (each with `class`, `message`) for classes that could not be checked.

### Example output

```
Checking Liskov Substitution Principle...

[FAIL] MyClass1
       -> MyClass1::doSomething() — contract MyInterface1 — @throws RuntimeException declared in docblock but not allowed by the contract
[PASS] MyClass2

Checking Interface Segregation Principle...

[PASS] MyClass1
[FAIL] RobotWorker
       -> RobotWorker — interface WorkerInterface — Method eat() is empty (no statements) — interface may be too wide for this class.
       -> RobotWorker — interface WorkerInterface — Method sleep() is empty (no statements) — interface may be too wide for this class.
[PASS] HumanWorker

Classes checked: 4
Passed: 2 / 4
Total violations: 3
```

- **Exit code**: `0` if all classes pass, `1` if any violation or load error is found (suitable for CI).
- **JSON report**: Use `--json` to write a report to stdout: `{ "violations": [...], "errors": [...] }`.

## Limitations

### LSP
- **Limited dynamic call resolution** — `$variable->method()` calls are followed only when the variable type can be statically resolved: parameter type hints (e.g. `function doSomething(Helper $helper)`) and simple local assignments (`$var = new ClassName()`). Dynamic calls where the variable type cannot be determined (e.g. untyped parameter, factory return, or complex control flow) are not followed. Trait methods used via `use SomeTrait` are analyzed, but `$this->method()` calls within a trait body are not resolved to the using class.
- **No flow analysis** — e.g. `$e = new E(); throw $e;` is not resolved (we only handle `throw new X` and re-throws of catch variables).
- **Parameter contravariance via Reflection only** — parameter type contravariance is checked on loaded classes. Since PHP itself enforces parameter compatibility at class load time, most violations are caught by the engine before the checker runs. The check is still useful as part of a comprehensive LSP report.

### ISP
- **Single-statement stubs only** — the empty method checker detects single-statement patterns (`throw new BadMethodCallException(...)`, `return;`, `return null;`). Multi-statement stubs or more complex "do nothing" patterns are not detected.
- **No partial usage analysis** — the checker does not yet analyze how consumers use interface-typed parameters (i.e. which methods are actually called). This is planned for a future release.
- **`BadMethodCallException` only** — only `BadMethodCallException` (and subclasses) are recognized as "not implemented" markers. Generic exceptions like `RuntimeException` are intentionally excluded to avoid false positives.

### General
- **Reflection-based** — only works on loadable PHP code (files that can be parsed and reflected). When scanning, a `vendor/autoload.php` is loaded automatically if found in or near the target paths.

## License

MIT.
