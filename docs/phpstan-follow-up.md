PHPSTAN
2. Execute `composer ci:test:php:phpstan` (using `.build/phpstan.neon`) to perform maximum-level static analysis.
2. Note each reported error, grouping them by the class that triggers the violation.
3. For every affected class, create or update follow-up tasks that reference the exact PHPStan messages and relevant file/line numbers.
4. Implement fixes per class—respecting the project’s design and documentation guidelines—and rerun PHPStan until it reports no errors.

PHPUNIT
1. From the project root, execute `composer ci:test:php:unit` to run the full PHPUnit suite (`.build/phpunit.xml` configuration).
2. Capture the console output and identify every failing test, including file paths and failure messages.
3. For each failure, implement the necessary fixes in the relevant source or test files while adhering to the repository’s PHP 8.4+ conventions.
4. Rerun the PHPUnit command until it exits successfully, and document the resolved failures.


---

## PHPStan run 2024-07-05

### Core/ValueConverters.php
- [x] `function.alreadyNarrowedType`: Call to function `is_numeric()` with `float|int` will always evaluate to true at lines 196, 209, and 228.
- [x] `booleanOr.alwaysFalse`: Result of `||` is always false at lines 196, 209, and 228.

### Curate/Resolver/RegionsResolver.php
- [x] `arrayValues.list`: `array_values()` called on an array that is already a list at lines 127 and 188.

### Curate/Exif/ValueFactory.php
- [x] `nullsafe.neverNull`: Nullsafe property access before `??` operator is unnecessary at lines 631-643.
- [x] `function.alreadyNarrowedType`: `array_is_list()` with `list<float>` always true at line 675.

### MakerNotes/Apple/BinaryPlistDecoder.php
- [x] `missingType.iterableValue`: Return type lacks iterable value type in `decode()` (line 48), `parseObject()` (line 119), `parseArray()` (line 242), and `parseDictionary()` (line 267).
- [x] `return.type`: `BinaryPlistDecoder::decode()` should return `array<int|string, array|bool|float|int|string|null>|bool|float|int|string|null` but returns `array|bool|float|int|string|null` at line 77.
- [x] `identical.alwaysFalse`: Strict comparison `=== false` always false at lines 83, 174, 185, 202, 214, 227, and 353.
- [x] `cast.useless`: Casting to int something already int at line 116.
- [x] `offsetAccess.nonOffsetAccessible`: Cannot access offset `1` on `array|false` at lines 180 and 191.
- [x] `cast.double`: Cannot cast `mixed` to float at lines 180 and 191.
- [x] `cast.int`: Cannot cast `mixed` to int at line 362 (reported twice).

### MakerNotes/AppleDecoder.php
- [x] `argument.type`: Parameter `$dictionary` of `AppleDecoder::buildAppleMakerNotes()` expects `array<string, array|bool|float|int|string|null>`, `array<int|string, array|bool|float|int|string|null>` given at line 77.
- [x] `missingType.iterableValue`: Parameter `$dictionary` lacks iterable value type annotations in `buildAppleMakerNotes()` (line 83), `stringValue()` (line 137), `floatValue()` (line 156), `intValue()` (line 179), `floatList()` (line 204), `extractFlags()` (line 246), and parameter `$value` in `boolValue()` (line 266).

### Parse/Icc/IccDecoder.php
- [x] `cast.int`: Cannot cast mixed to int at line 355.

### Parse/Xmp/XmpParser.php
- [x] `arrayValues.list`: `array_values()` called on a list at lines 176 and 186.

