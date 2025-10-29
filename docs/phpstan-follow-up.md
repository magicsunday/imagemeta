PHPSTAN
1. Execute `composer ci:test:php:phpstan` (using `.build/phpstan.neon`) to perform maximum-level static analysis.
2. Note each reported error, grouping them by the class that triggers the violation.
3. For every affected class, create or update follow-up tasks that reference the exact PHPStan messages and relevant file/line numbers.
4. Implement fixes per class—respecting the project’s design and documentation guidelines—and rerun PHPStan until it reports no errors.

PHPUNIT
1. From the project root, execute `composer ci:test:php:unit` to run the full PHPUnit suite (`.build/phpunit.xml` configuration).
2. Capture the console output and identify every failing test, including file paths and failure messages.
3. For each failure, implement the necessary fixes in the relevant source or test files while adhering to the repository’s PHP 8.4+ conventions.
4. Rerun the PHPUnit command until it exits successfully, and document the resolved failures.

## Follow-up tasks

### tests/Support/ExifExpectationAssertions.php
- [x] Resolve `missingType.iterableValue` for `$expected` parameter at `tests/Support/ExifExpectationAssertions.php:48` by providing explicit array shape annotations recognised by PHPStan.
- [x] Resolve `method.dynamicName` reported at `tests/Support/ExifExpectationAssertions.php:120` by replacing dynamic EXIF document lookups with typed callables.
- [x] Resolve `missingType.iterableValue` for `$expected` parameter at `tests/Support/ExifExpectationAssertions.php:205` by documenting the array shape consumed by `assertModelMatches()`.

### tests/Support/GpsTiffBuilder.php
- [x] Resolve `switch.type` raised at `tests/Support/GpsTiffBuilder.php:88` by constraining the definition modes and removing unreachable branches.
- [x] Resolve `argument.type` diagnostics at `tests/Support/GpsTiffBuilder.php:89-94` by introducing typed payload definitions and validating mode-specific payloads.
- [x] Resolve `foreach.nonIterable` at `tests/Support/GpsTiffBuilder.php:103` through explicit rational payload validation.
- [x] Resolve `offsetAccess.nonOffsetAccessible` and `return.type` at `tests/Support/GpsTiffBuilder.php:157` by asserting unpack results and throwing a `LogicException` when packing fails.
