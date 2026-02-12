# Changelog

## [Unreleased]

## [0.4.0] - 2026-02-13

### Added
- **Exception hierarchy** — A thrown exception type is now allowed by the contract if it is the same as or a **subclass** of any exception declared in the contract (LSP-compliant). Example: contract `@throws RuntimeException`, implementation throws `UnexpectedValueException` → no violation. This removes false positives when implementations throw more specific exception types.

## [0.3.5] - 2026-02-13

### Fixed
- **Binary** — In `lsp-checker`, the exception message when `vendor/autoload.php` is not found now uses the correct PHP constant `__DIR__` instead of `__dir__` (which was displayed literally).

## [0.3.4] - 2026-02-13

### Added
- **JSON report** — Load/reflection errors are now included in `--json` output under an `errors` key. The report shape is `{ "violations": [...], "errors": [{ "class": "...", "message": "..." }] }`.

### Added
- **GitHub Actions CI** — workflow `.github/workflows/ci.yml` runs PHPUnit on push/PR for PHP 8.2, 8.3, 8.4
- **PHPUnit tests** — `tests/LiskovSubstitutionPrincipleCheckerTest.php` uses the example classes (MyClass1–MyClass5) to assert expected LSP violations and passes
- `phpunit.xml.dist` for test configuration

### Changed
- **CLI** — `<directory>` is now required; without arguments the script prints usage and exits with code 2
- Removed `--example` option; the built-in example is only used by unit tests (`vendor/bin/phpunit`)

## [0.3.3] - 2026-02-13

### Fixed
- **CLI** — When a class triggers a `ReflectionException` (e.g. not loadable), the error is no longer mixed with LSP violations: it is shown as a single "Error: …" line, not added to the violation count, and excluded from the `--json` output so the report stays an array of violations only.

## [0.3.2] - 2026-02-12

### Changed
- **CLI binary** — entry point renamed from `lsp-checker.php` to `lsp-checker` (no extension); use `vendor/bin/lsp-checker <options>` after `composer require tivins/poc-liskov-check`
- Autoload path now works both when run from package root and when installed as a dependency (`dirname(__DIR__) . '/autoload.php'` when in `vendor/bin`)
- Fixed `__DIR__` constant (was incorrectly `__dir__`)

## [0.3.1] - 2026-02-12

### Fixed
- **CI PHP 8.2** — PHPUnit contraint à `^11.0` (compatible PHP 8.2) au lieu de `12.5.x-dev` (PHP ≥ 8.3) pour que le workflow GitHub Actions passe sur la matrice PHP 8.2, 8.3, 8.4

## [0.3.0] - 2026-02-12

### Added
- **Directory scanning** — pass a directory path as argument to recursively discover and check all PHP classes (`ClassFinder`)
- `--example` CLI option to explicitly run the built-in example
- `ClassFinder` class (`src/Process/ClassFinder.php`) — uses php-parser to extract FQCN from PHP files; auto-detects `vendor/autoload.php` in the target project

### Changed
- `lsp-checker` (formerly `lsp-checker.php`) now accepts `<path>` as first positional argument; without arguments, the built-in example still runs (backward-compatible)

## [0.2.0] - 2026-02-12

### Added
- `ThrowsDetector::getActualThrows()` — detects exceptions actually thrown in method bodies via AST analysis (`nikic/php-parser`)
  - Handles `throw new ClassName()` (direct throws)
  - Handles re-throws in catch blocks (`catch (E $e) { throw $e; }`)
  - Handles conditional throws (`if (...) throw new E()`)
  - Follows `$this->method()` calls recursively within the same class (transitive throw detection)
  - Circular call protection to prevent infinite recursion
  - Full FQCN resolution via `NameResolver` (supports `use` statements and namespaces)
- Internal file AST cache in `ThrowsDetector` to avoid re-parsing the same file
- New example `MyClass4` — class with throw in code but no `@throws` docblock (AST-only detection)
- New example `MyClass5` — class with throw via private method delegation (transitive AST detection)

### Changed
- `LiskovSubstitutionPrincipleChecker::checkThrowsViolations()` now checks both docblock `@throws` and actual throw statements (AST)
- Violation messages now distinguish between docblock violations and code (AST) violations

## [0.1.0] - 2026-02-12

### Added
- `ThrowsDetector` class to parse `@throws` declarations from docblocks
- `LspViolation` value object for structured violation reporting
- `LiskovSubstitutionPrincipleChecker` with full exception contract checking
  - Checks against all implemented interfaces
  - Checks against parent class
  - Detects `@throws` declarations not allowed by the contract
- Entry point script `lsp-checker` (formerly `lsp-checker.php`) with colored pass/fail output
- Example violation classes for testing (`liskov-principles-violation-example.php`)
