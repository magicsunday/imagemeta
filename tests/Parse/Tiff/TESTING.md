# Negative and Fuzz Testing Documentation

This document describes the negative and fuzz testing infrastructure added for EXIF, TIFF, and BigTIFF parsing.

## Overview

The test suite adds **55 comprehensive negative tests** across four test classes to ensure robust handling of malformed, corrupted, and edge-case TIFF/EXIF data.

## Test Files

### 1. TiffExifReaderNegativeTest.php (15 tests)

Tests for fundamental TIFF structure violations and error handling.

**Coverage:**
- Invalid byte order markers (not "II" or "MM")
- Invalid magic numbers (not 0x002A or 0x002B)
- IFD offsets beyond blob boundaries
- Truncated TIFF headers and IFD data
- BigTIFF header validation (offset size, reserved field)
- RATIONAL values with zero denominators
- SRATIONAL values with extreme values (INT_MIN, INT_MAX)
- Cyclic IFD chain detection
- Unsupported TIFF type codes
- Truncated IFD entries
- Huge entry counts causing overflows

**Key Test Patterns:**
```php
// Testing invalid headers
$blob = 'XX' . pack('n', TiffConst::MAGIC_CLASSIC) . pack('N', 8);
$this->expectException(ParseError::class);
$reader->parseFromBlob($blob);

// Testing bounds violations
$blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 0xFFFFFF);
$this->expectException(BoundsError::class);
$reader->parseFromBlob($blob);
```

### 2. TiffExifReaderBigTiffTest.php (11 tests)

BigTIFF-specific edge cases and 64-bit handling.

**Coverage:**
- Offset sizes (8 and 16 bytes)
- Invalid offset sizes (rejected)
- Non-zero reserved field (rejected)
- Zero first IFD offset handling
- 64-bit entry counts
- LONG8 (TYPE_LONG8 = 16) unsigned 64-bit integers
- SLONG8 (TYPE_SLONG8 = 17) signed 64-bit integers
- IFD8 (TYPE_IFD8 = 18) 64-bit IFD pointers
- Offsets beyond 4 GiB boundary
- Value offsets exceeding blob size
- Truncated BigTIFF entries
- Extremely large entry counts

**Key Test Patterns:**
```php
// BigTIFF header with 64-bit offsets
$blob = 'II'
    . pack('v', TiffConst::MAGIC_BIG)
    . pack('v', 8)      // Offset size
    . pack('v', 0)      // Reserved
    . pack('P', 16);    // First IFD at offset 16

// Testing LONG8 type
$blob .= pack('P', 1);  // 1 entry (64-bit count)
$blob .= pack('v', 0x0100)               // Tag
    . pack('v', TiffConst::TYPE_LONG8)   // Type
    . pack('P', 1)                       // Count (64-bit)
    . pack('P', 1920);                   // Value (inline, 64-bit)
```

### 3. TiffExifReaderFuzzTest.php (16 tests)

Fuzz-style tests simulating corrupted or malicious inputs.

**Coverage:**
- Empty blobs
- Too-short blobs (truncated headers)
- Random garbage data
- Null-byte-only data
- Entry counts causing integer overflow
- Overlapping entry data regions
- RATIONAL with both values zero
- RATIONAL with maximum unsigned values (0xFFFFFFFF)
- SRATIONAL with various sign combinations
- Deep IFD chains (5+ levels)
- ASCII strings with embedded nulls
- Entries with zero count
- Mixed endianness patterns
- UNDEFINED type with arbitrary bytes

**Key Test Patterns:**
```php
// Fuzz: random garbage
$blob = str_repeat("\xFF", 100);
$this->expectException(ParseError::class);

// Fuzz: overflow conditions
$blob .= pack('V', 0x7FFFFFFF);  // INT_MAX count
$this->expectException(BoundsError::class);

// Edge case: rational with both zero
$blob = $this->buildTiffWithRational(0, 0);
$result = $reader->parseFromBlob($blob);  // Should not throw
```

### 4. TiffExifReaderUserCommentTest.php (13 tests)

UserComment encoding and special EXIF field edge cases.

**Coverage:**
- ASCII encoding prefix ("ASCII\0\0\0")
- JIS encoding prefix ("JIS\0\0\0\0\0")
- UNICODE encoding prefix ("UNICODE\0")
- Undefined encoding (8 null bytes)
- Truncated encoding prefixes
- Invalid encoding markers
- Empty UserComment
- Encoding-only UserComment (no text)
- Non-printable characters in comments
- Maximum length comments (1000+ chars)
- MakerNote field handling
- Empty MakerNote

**Key Test Patterns:**
```php
// UserComment with encoding prefix
$comment = "ASCII\0\0\0Hello World";
$blob = $this->buildTiffWithUserComment($comment);

// Truncated prefix
$comment = "ASC";  // Incomplete "ASCII\0\0\0"
$result = $reader->parseFromBlob($blob);  // Should handle gracefully
```

## Testing Strategy

### Synthetic Binary Blobs

All tests use hand-crafted binary blobs rather than real image files:

```php
private function buildMinimalTiffWithRational(int $numerator, int $denominator): string
{
    $blob = 'II'  // Little-endian
        . pack('v', TiffConst::MAGIC_CLASSIC)
        . pack('V', 8);  // IFD at offset 8
    
    // Add IFD entries...
    return $blob;
}
```

**Benefits:**
- Precise control over malformed data
- No external file dependencies
- Fast test execution
- Easy to understand and maintain

### Exception Testing

Tests verify that appropriate exceptions are thrown:

```php
$this->expectException(ParseError::class);
$this->expectExceptionMessage('Bad TIFF byte order');
$reader->parseFromBlob($invalidBlob);
```

**Exception Types:**
- `ParseError` - Malformed structure, invalid values
- `BoundsError` - Out-of-bounds access, overflow

### Non-Throwing Tests

Some tests verify graceful handling without exceptions:

```php
// Zero denominator should be parsed (application decides how to handle)
$blob = $this->buildTiffWithRational(100, 0);
$result = $reader->parseFromBlob($blob);
self::assertNotNull($result->ifd0);
```

## Running the Tests

### All Unit Tests
```bash
composer ci:test:php:unit
```

### Specific Test File
```bash
./build/bin/phpunit tests/Parse/Tiff/TiffExifReaderNegativeTest.php
```

### With Coverage
```bash
composer ci:test:php:unit:coverage
```

Expected coverage: ≥90% for TiffExifReader error paths.

## Extending the Tests

### Adding New Negative Tests

1. Choose the appropriate test file:
   - **Negative**: General structure/format violations
   - **BigTiff**: BigTIFF-specific (64-bit) edge cases
   - **Fuzz**: Random/corrupted input patterns
   - **UserComment**: Encoding and special field tests

2. Follow the naming pattern:
   ```php
   #[Test]
   public function rejects<WhatIsBeingRejected>(): void
   // or
   public function handles<EdgeCase>(): void
   ```

3. Build a minimal reproducing blob:
   ```php
   private function build<Description>(params): string
   {
       $blob = 'II' . pack('v', TiffConst::MAGIC_CLASSIC) . pack('V', 8);
       // ... add minimal required structure
       return $blob;
   }
   ```

4. Add appropriate assertions:
   ```php
   $this->expectException(ParseError::class);
   // or
   self::assertNotNull($result->ifd0);
   ```

### Example: Adding a New Edge Case

```php
/**
 * Tests handling of RATIONAL with negative numerator (invalid but might appear).
 */
#[Test]
public function handlesRationalWithNegativeNumerator(): void
{
    // RATIONAL should only have unsigned values, but test parsing
    $blob = $this->buildTiffWithRational(-100, 200);
    
    $reader = new TiffExifReader();
    $result = $reader->parseFromBlob($blob);
    
    // Verify it parses (values will be reinterpreted as unsigned)
    self::assertNotNull($result->ifd0);
}
```

## EXIF Specification References

All tests reference relevant EXIF/TIFF specifications:

- **EXIF 3.0 §4.5**: TIFF header layout and IFD traversal
- **EXIF 3.0 §4.5.1**: Byte order and magic numbers
- **EXIF 3.0 §4.5.2**: IFD structure and field types (Table 3)
- **EXIF 3.0 §4.6.5**: UserComment and MakerNote (Table 11)
- **TIFF 6.0 §2**: File structure
- **TIFF 6.0 §8**: Baseline IFD

## Common TIFF Type Codes

```php
TiffConst::TYPE_BYTE      = 1   // 8-bit unsigned
TiffConst::TYPE_ASCII     = 2   // 8-bit ASCII
TiffConst::TYPE_SHORT     = 3   // 16-bit unsigned
TiffConst::TYPE_LONG      = 4   // 32-bit unsigned
TiffConst::TYPE_RATIONAL  = 5   // Two LONGs (num/den)
TiffConst::TYPE_UNDEFINED = 7   // 8-bit arbitrary
TiffConst::TYPE_SLONG     = 9   // 32-bit signed
TiffConst::TYPE_SRATIONAL = 10  // Two SLONGs (signed num/den)
TiffConst::TYPE_LONG8     = 16  // 64-bit unsigned (BigTIFF)
TiffConst::TYPE_SLONG8    = 17  // 64-bit signed (BigTIFF)
TiffConst::TYPE_IFD8      = 18  // 64-bit IFD pointer (BigTIFF)
```

## Security Considerations

These tests validate security-critical parsing behaviors:

1. **Bounds Checking**: All offset and length values are validated
2. **Integer Overflow**: Large counts are rejected before allocation
3. **Cyclic References**: IFD chains are tracked to prevent infinite loops
4. **Resource Exhaustion**: Unreasonable sizes trigger errors early
5. **Division by Zero**: RATIONAL denominators of zero are parsed but not evaluated

## Future Work

Potential areas for expansion:

- [ ] Property-based testing with PHP generators
- [ ] Integration with fuzzing tools (AFL, libFuzzer)
- [ ] Extended UserComment encoding tests (Shift-JIS, etc.)
- [ ] Multi-page TIFF edge cases
- [ ] Corrupted maker note structures
- [ ] GPS coordinate edge cases (extreme lat/long)
- [ ] DateTime parsing edge cases
- [ ] Strip/Tile offset overflow scenarios

## Maintenance

When updating TiffExifReader:

1. Run negative tests to ensure error handling still works
2. Add tests for new error conditions
3. Update this documentation if test patterns change
4. Maintain ≥90% coverage including error paths

## Contact

For questions or issues with these tests, refer to AGENTS.md or open an issue.
