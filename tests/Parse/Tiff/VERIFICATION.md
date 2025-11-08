# Test Verification Checklist

This document tracks verification steps that need to be completed once PHP 8.4 is available in the CI environment.

## Required Environment

- **PHP**: 8.4+
- **Composer**: Latest version
- **Extensions**: As defined in composer.json (ctype, date, fileinfo, hash, iconv, json, pcre, xmlreader)

## Pre-Verification Setup

```bash
cd /home/runner/work/imagemeta/imagemeta
composer install
```

## Test Execution

### 1. Run All Unit Tests

```bash
composer ci:test:php:unit
```

**Expected Result:**
- All 55 new tests should pass
- Existing tests should remain passing
- No warnings or notices

**Verification Points:**
- [ ] TiffExifReaderNegativeTest: 15/15 tests pass
- [ ] TiffExifReaderBigTiffTest: 11/11 tests pass  
- [ ] TiffExifReaderFuzzTest: 16/16 tests pass
- [ ] TiffExifReaderUserCommentTest: 13/13 tests pass
- [ ] No regressions in existing tests

### 2. Coverage Analysis

```bash
composer ci:test:php:unit:coverage
```

**Expected Result:**
- Coverage for TiffExifReader error paths: ≥90%
- Overall project coverage: maintained or improved

**Verification Points:**
- [ ] Coverage report generated in `.build/coverage/`
- [ ] TiffExifReader.php coverage ≥90%
- [ ] Error handling paths covered:
  - [ ] ParseError branches
  - [ ] BoundsError branches
  - [ ] Invalid type handling
  - [ ] Overflow detection
  - [ ] Cyclic chain detection

**Review Coverage Gaps:**
If coverage is below 90%, identify uncovered code paths and add targeted tests.

### 3. Static Analysis (PHPStan)

```bash
composer ci:test:php:phpstan
```

**Expected Result:**
- No errors in new test files
- Maximum level analysis passing

**Verification Points:**
- [ ] No type errors in test files
- [ ] No undefined variables
- [ ] No unreachable code
- [ ] Proper exception handling

**Common Issues to Check:**
- Missing type hints
- Incorrect parameter types in pack()
- Unused variables in helper methods

### 4. Code Style (PHPCS)

```bash
composer ci:cgl
```

**Expected Result:**
- All files comply with PSR-12
- No style violations

**Verification Points:**
- [ ] Proper indentation (4 spaces)
- [ ] No trailing whitespace
- [ ] Proper line length (≤120 chars)
- [ ] Proper imports and use statements
- [ ] Correct docblock formatting

**Auto-Fix:**
If violations exist, run:
```bash
composer ci:cgl
```

### 5. Code Duplication (CPD)

```bash
composer ci:test:php:cpd
```

**Expected Result:**
- No significant code duplication
- Helper methods properly extracted

**Verification Points:**
- [ ] No duplicated blob-building logic
- [ ] Common patterns properly abstracted
- [ ] Acceptable duplication in test data setup

### 6. Rector (Code Modernization)

```bash
composer ci:test:php:rector
```

**Expected Result:**
- No suggested changes
- Code follows modern PHP 8.4 patterns

**Verification Points:**
- [ ] Using native PHP 8.4 features where appropriate
- [ ] No outdated patterns

## Manual Verification

### Test Quality Review

Review each test file for:

- [ ] Clear test names describing what is tested
- [ ] Minimal synthetic blobs (no unnecessary data)
- [ ] Proper exception assertions
- [ ] Complete docblock comments
- [ ] EXIF specification references

### Edge Case Coverage

Verify edge cases are tested:

- [ ] Empty inputs
- [ ] Minimum valid inputs
- [ ] Maximum valid inputs  
- [ ] Just-beyond-valid inputs
- [ ] Corrupted data patterns

### Error Message Quality

Check that error messages are:

- [ ] Clear and actionable
- [ ] Include context (offset, tag, type)
- [ ] Consistent with existing messages

## Security Review

### Bounds Checking

Verify tests cover:

- [ ] All offset validations
- [ ] All length validations
- [ ] Integer overflow scenarios
- [ ] Buffer overflow prevention

### Resource Exhaustion

Verify tests for:

- [ ] Huge entry counts (rejected early)
- [ ] Large allocations (prevented)
- [ ] Infinite loops (cycle detection)

### Malicious Input

Verify tests for:

- [ ] Crafted offsets pointing to sensitive data
- [ ] Overlapping data regions
- [ ] Type confusion attacks
- [ ] Integer wraparound

## Integration Testing

If the project has integration tests:

```bash
# Run full integration test suite
composer ci:test
```

**Verification Points:**
- [ ] Integration tests pass
- [ ] No performance regressions
- [ ] Memory usage acceptable

## Performance Validation

### Test Execution Time

```bash
time composer ci:test:php:unit
```

**Expected:**
- Total test time: <5 seconds for new tests
- No individual test >1 second

**Verification Points:**
- [ ] Fast test execution
- [ ] No timeout issues
- [ ] Efficient blob construction

## Documentation Review

### Test Documentation

- [ ] TESTING.md is complete and accurate
- [ ] Examples are runnable
- [ ] Common patterns documented
- [ ] Extension guide is clear

### Code Comments

- [ ] Docblocks reference correct EXIF sections
- [ ] Complex logic is explained
- [ ] Edge cases are documented

## Known Limitations

Document any known issues or limitations:

### PHP Version Dependency

- Tests written for PHP 8.4
- May have compatibility issues with PHP 8.3
- Specifically: pack('P') behavior, 64-bit handling

### Platform Differences

- Endianness handling tested for both LE/BE
- 32-bit vs 64-bit platform considerations
- Windows vs Unix line endings

### Test Coverage Gaps

Document any areas not fully covered:

- [ ] Property-based testing (future work)
- [ ] Fuzzer integration (future work)
- [ ] Some exotic encoding combinations

## Sign-Off

Once all verification steps are complete:

- [ ] All tests pass
- [ ] Coverage ≥90%
- [ ] No PHPStan errors
- [ ] No code style violations
- [ ] Documentation complete
- [ ] Security review done

**Verified By:** _________________
**Date:** _________________
**PHP Version:** _________________
**Notes:** _________________

## Troubleshooting

### Common Issues

**Issue: Tests fail with "Cannot unpack"**
- Check pack() format codes for PHP 8.4 compatibility
- Verify endianness handling
- Check string lengths

**Issue: Coverage below 90%**
- Run coverage report in HTML mode
- Identify uncovered branches
- Add targeted tests for gaps

**Issue: PHPStan errors in test files**
- Check PHPDoc types match actual types
- Verify #[UsesClass] attributes are complete
- Check for undefined class references

**Issue: Style violations**
- Run `composer ci:cgl` to auto-fix
- Review PSR-12 compliance
- Check for trailing whitespace

### Getting Help

- Check AGENTS.md for contribution guidelines
- Review TESTING.md for test patterns
- Open an issue with specific error messages
- Include PHP version and environment details
