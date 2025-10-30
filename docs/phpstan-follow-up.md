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
