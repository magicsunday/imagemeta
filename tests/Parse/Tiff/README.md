# TIFF/EXIF Negative and Fuzz Testing Suite

This directory contains comprehensive negative and fuzz tests for the TIFF/EXIF parser.

## 📊 Summary

- **52 negative/fuzz tests** across 4 test files
- **~1,500 lines** of test code
- **~650 lines** of documentation
- **100% synthetic** test data (no external files)

## 📁 Files

### Test Files

| File | Tests | Focus Area |
|------|-------|------------|
| `TiffExifReaderNegativeTest.php` | 12 | General malformed structures |
| `TiffExifReaderBigTiffTest.php` | 11 | BigTIFF 64-bit edge cases |
| `TiffExifReaderFuzzTest.php` | 17 | Fuzz-style random inputs |
| `TiffExifReaderUserCommentTest.php` | 12 | Encoding and special fields |
| **Total** | **52** | |

### Documentation

- **TESTING.md** - Comprehensive test documentation and patterns
- **VERIFICATION.md** - Validation checklist for PHP 8.4 environment
- This README - Quick reference

### Existing Tests

- `TiffConstTest.php` - Constants validation (pre-existing)

## 🎯 Coverage Areas

### TIFF Structure Validation
- ✅ Byte order markers (II/MM)
- ✅ Magic numbers (0x002A classic, 0x002B BigTIFF)
- ✅ IFD offsets and chaining
- ✅ Entry counts and formats
- ✅ Truncated data detection

### BigTIFF Specific
- ✅ 64-bit offsets and counts
- ✅ Offset sizes (8 or 16 bytes)
- ✅ Reserved field validation
- ✅ LONG8/SLONG8/IFD8 types
- ✅ Offsets beyond 4 GiB

### Error Handling
- ✅ ParseError for malformed structures
- ✅ BoundsError for out-of-bounds access
- ✅ Integer overflow prevention
- ✅ Cyclic IFD chain detection
- ✅ Resource exhaustion prevention

### Edge Cases
- ✅ RATIONAL/SRATIONAL with zero denominators
- ✅ Extreme values (INT_MIN, INT_MAX, UINT_MAX)
- ✅ Empty and truncated inputs
- ✅ Random garbage data
- ✅ Overlapping data regions

### Special Fields
- ✅ UserComment encodings (ASCII, JIS, UNICODE)
- ✅ MakerNote handling
- ✅ Encoding prefix edge cases
- ✅ Non-printable characters

## 🚀 Quick Start

### Run All Tests

```bash
composer ci:test:php:unit
```

### Run Specific Test File

```bash
./build/bin/phpunit tests/Parse/Tiff/TiffExifReaderNegativeTest.php
```

### Check Coverage

```bash
composer ci:test:php:unit:coverage
```

Expected: ≥90% coverage for TiffExifReader

## 📖 Documentation

For detailed information:

- **[TESTING.md](TESTING.md)** - Complete testing guide
  - Test patterns and strategies
  - How to extend tests
  - EXIF specification references
  - Security considerations

- **[VERIFICATION.md](VERIFICATION.md)** - Validation checklist
  - Test execution procedures
  - Coverage analysis steps
  - Static analysis verification
  - Troubleshooting guide

## 🔒 Security

These tests validate critical security properties:

| Property | Validation |
|----------|------------|
| Bounds checking | All offsets/lengths validated |
| Integer overflow | Large counts rejected early |
| Cyclic references | IFD chains tracked |
| Division by zero | Gracefully handled |
| Resource exhaustion | Unreasonable sizes rejected |

## 📚 Specifications

Tests reference:

- **EXIF 3.0** (primary) - §4.5, §4.6.5
- **EXIF 2.32** - §4.5, §4.6.5  
- **TIFF 6.0** - §2, §8
- **BigTIFF** specification

All specification PDFs are in `docs/` directory.

## ✅ Quality Standards

Tests follow:

- ✅ PHPUnit 12 attributes (`#[Test]`, `#[CoversClass]`, `#[UsesClass]`)
- ✅ PSR-12 code style
- ✅ Strict types (`declare(strict_types=1);`)
- ✅ Complete docblocks
- ✅ English comments and documentation
- ✅ No external dependencies

## 🔧 Adding New Tests

1. Choose the appropriate test file based on test type
2. Follow naming convention: `rejects<What>()` or `handles<EdgeCase>()`
3. Build minimal synthetic TIFF blob
4. Add appropriate assertions
5. Document with EXIF spec references

See [TESTING.md](TESTING.md) for detailed guide.

## 🐛 Troubleshooting

### Tests Won't Run

**PHP Version Issue:**
```
Error: requires PHP ^8.4
```
→ See VERIFICATION.md for PHP 8.4 setup

**Missing Dependencies:**
```bash
composer install
```

### Common Test Failures

**Pack Format Issues:**
- Check pack() format codes for PHP version
- Verify endianness handling

**Coverage Gaps:**
- Run HTML coverage report
- Identify uncovered branches
- Add targeted tests

See [VERIFICATION.md](VERIFICATION.md) troubleshooting section.

## 📊 Statistics

```
Language                     Files        Lines        Code     Comment
─────────────────────────────────────────────────────────────────────
PHP Tests                        4         1,500       1,200         300
Documentation (MD)               2           650         650           0
─────────────────────────────────────────────────────────────────────
Total                            6         2,150       1,850         300
```

## 🤝 Contributing

When modifying TiffExifReader:

1. Run existing negative tests to ensure error handling works
2. Add tests for new error conditions  
3. Update documentation if patterns change
4. Maintain ≥90% coverage including error paths

## 📝 License

See [LICENSE](../../../LICENSE) file in repository root.

## 📧 Contact

For questions about these tests, see [AGENTS.md](../../../AGENTS.md) or open an issue.

---

**Last Updated:** 2025-11-08  
**Test Count:** 52  
**Coverage Target:** ≥90%  
**PHP Version:** 8.4+
