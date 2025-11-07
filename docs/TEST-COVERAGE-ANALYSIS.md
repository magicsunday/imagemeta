# Test Coverage Analysis

**Date**: 2025-11-07  
**Target Coverage**: 90%+  
**Analysis Scope**: Unit tests, integration tests, edge cases

---

## 1. Executive Summary

The ImageMeta library has **good test infrastructure** with 80+ test files covering value objects, parsers, and core functionality. However, there are gaps in coverage for:
- Edge cases in TIFF/EXIF parsing
- EXIF 3.0-specific features
- Error handling and corrupt data scenarios
- BigTIFF edge cases

**Recommendations**:
1. Add negative tests for parsers
2. Create synthetic test data for edge cases
3. Test EXIF 3.0 features explicitly
4. Add integration tests with diverse formats

---

## 2. Current Test Structure

### Test Organization
```
tests/
├── Convenience/          # Convenience API tests
├── Curate/              # Metadata curation tests
├── Detect/              # Format detection tests
├── Model/               # Model layer tests
├── Parse/               # Parser tests
│   ├── Icc/            # ICC profile parsing
│   ├── IsoBmff/        # ISOBMFF container parsing
│   ├── Jpeg/           # JPEG parsing
│   ├── Tiff/           # TIFF/EXIF parsing
│   └── Xmp/            # XMP parsing
└── Value/               # Value object tests (80% of tests)
```

### Test File Count by Category
- **Value Objects**: ~60 files (Camera, Exposure, GPS, etc.)
- **Parsers**: ~7 files
- **Core/Model**: ~10 files
- **Integration**: Limited

---

## 3. Well-Tested Areas ✅

### 3.1 Value Objects (Excellent Coverage)
**Files**: `tests/Value/*Test.php`

**Strengths**:
- All value objects have dedicated test files
- Comprehensive getter tests
- Type conversion tests
- Enum mapping tests

**Examples**:
- `CameraTest.php`: cameraMake(), cameraModel(), serialNumber()
- `ExposureTest.php`: ISO, aperture, shutter speed, EV
- `GpsTest.php`: coordinates, altitude, speed, direction
- `TemporalTest.php`: timestamp parsing, subsec, timezone

**Coverage Estimate**: 90-95%

### 3.2 Core Parsers (Good Coverage)
**Files**: `tests/Parse/*/Test.php`

**Well-Tested**:
- ✅ JpegExtractor: Segment extraction, APP1/APP13 detection
- ✅ XmpParser: XML parsing, namespace handling, arrays
- ✅ IsoBmffExtractor: Box parsing, EXIF/XMP extraction
- ✅ MpfParser: Multi-picture format handling
- ✅ IccDecoder: ICC profile decoding

**Coverage Estimate**: 80-85%

### 3.3 Enum Mappings (Complete Coverage)
**File**: `tests/Value/Enum/EnumMappingTest.php`

**Coverage**: 100% - all enums tested

---

## 4. Under-Tested Areas ⚠️

### 4.1 TIFF/EXIF Parser Edge Cases
**File**: `src/Parse/Tiff/TiffExifReader.php`

**Current Coverage**: ~70% (estimated)

**Missing Tests**:
1. **Circular IFD References**
   ```php
   // IFD0 → ExifIFD → SubIFD → points back to IFD0
   // Should detect and break loop
   #[Test]
   public function testCircularIfdReference(): void
   {
       $this->expectException(ParseError::class);
       // Create malformed TIFF with circular reference
   }
   ```

2. **Invalid IFD Offsets**
   ```php
   #[Test]
   public function testIfdOffsetBeyondFileSize(): void
   {
       // IFD offset = 0xFFFFFFFF (beyond file)
       $this->expectException(BoundsError::class);
   }
   ```

3. **Corrupt Entry Count**
   ```php
   #[Test]
   public function testExcessiveIfdEntryCount(): void
   {
       // Entry count = 10000 (unrealistic)
       $this->expectException(ParseError::class);
   }
   ```

4. **Mixed Endianness** (should never happen but test defense)
   ```php
   #[Test]
   public function testMixedEndianness(): void
   {
       // II header but MM-ordered entries
       $this->expectException(ParseError::class);
   }
   ```

5. **BigTIFF Specific**
   ```php
   #[Test]
   public function testBigTiffLargeOffset(): void
   {
       // Offset > 4GB (requires uint64)
       $offset = UInt64::fromString('5000000000');
       // Test proper handling
   }
   ```

**Recommendation**: Add `tests/Parse/Tiff/TiffExifReaderEdgeCaseTest.php` with 20+ edge case tests

---

### 4.2 EXIF 3.0 Features
**Files**: Various parsers

**Current Coverage**: ~60% (estimated)

**Missing Tests**:
1. **PreviewIFD Parsing** (when implemented)
   ```php
   #[Test]
   public function testPreviewIfdParsing(): void
   {
       // Test EXIF 3.0 file with PreviewIFD
       $metadata = $reader->read('fixtures/exif30-preview.jpg');
       $preview = $metadata->exifDoc?->previewImage();
       
       self::assertNotNull($preview);
       self::assertTrue($preview->isAvailable());
   }
   ```

2. **BigTIFF vs Classic TIFF**
   ```php
   #[Test]
   public function testClassicTiffMarker(): void
   {
       // 0x2A marker (Classic TIFF)
       $stream = $this->createTiffStream(classic: true);
       // Assert 4-byte offsets used
   }
   
   #[Test]
   public function testBigTiffMarker(): void
   {
       // 0x2B marker (BigTIFF)
       $stream = $this->createTiffStream(classic: false);
       // Assert 8-byte offsets used
   }
   ```

3. **EXIF Version Detection**
   ```php
   #[Test]
   public function testExifVersionDetection(): void
   {
       // Test files with EXIF 2.1, 2.2, 2.3, 2.32, 3.0
       foreach ($versions as $version => $file) {
           $exif = $reader->read($file)->exifDoc;
           self::assertSame($version, $exif->exifVersion());
       }
   }
   ```

**Recommendation**: Create `tests/Parse/Tiff/Exif30FeatureTest.php`

---

### 4.3 Error Handling and Recovery
**Files**: All parsers

**Current Coverage**: ~50% (estimated)

**Missing Tests**:

1. **Truncated Files**
   ```php
   #[Test]
   public function testTruncatedJpeg(): void
   {
       // JPEG header OK, but SOI marker incomplete
       $this->expectException(ParseError::class);
   }
   
   #[Test]
   public function testTruncatedExif(): void
   {
       // EXIF APP1 marker present but data truncated
       // Should handle gracefully
   }
   ```

2. **Invalid Tag Data**
   ```php
   #[Test]
   public function testInvalidTagType(): void
   {
       // IFD entry with invalid type (e.g., type = 255)
       // Should skip tag, not crash
   }
   
   #[Test]
   public function testInvalidRational(): void
   {
       // Rational with denominator = 0
       // Should return null, not divide by zero
   }
   ```

3. **String Encoding Errors**
   ```php
   #[Test]
   public function testInvalidUtf8(): void
   {
       // Tag with malformed UTF-8
       // Should handle gracefully (replace or skip)
   }
   
   #[Test]
   public function testNullTerminatorMissing(): void
   {
       // ASCII string without null terminator
       // Should handle based on count
   }
   ```

4. **XMP Malformed XML**
   ```php
   #[Test]
   public function testMalformedXml(): void
   {
       // XMP with unclosed tags
       // Should parse what's valid, skip invalid
   }
   ```

**Recommendation**: Create `tests/Parse/ErrorHandlingTest.php`

---

### 4.4 Integration Tests
**Current Coverage**: Limited

**Missing Tests**:

1. **Real-World File Formats**
   ```php
   #[Test]
   public function testCanonRawCr3(): void
   {
       // Test Canon CR3 (ISOBMFF + EXIF)
   }
   
   #[Test]
   public function testAppleHeic(): void
   {
       // Test iPhone HEIC (EXIF 3.0 features)
   }
   
   #[Test]
   public function testSonyArw(): void
   {
       // Test Sony ARW (TIFF-based RAW)
   }
   ```

2. **Multi-Format Metadata**
   ```php
   #[Test]
   public function testExifXmpCombination(): void
   {
       // File with both EXIF and XMP
       // Ensure both are parsed and merged correctly
   }
   ```

3. **Large Files**
   ```php
   #[Test]
   public function testLargeJpeg(): void
   {
       // 100MB+ JPEG
       // Ensure streaming doesn't exhaust memory
   }
   ```

**Recommendation**: Create `tests/Integration/` directory with format-specific tests

---

### 4.5 Performance & Memory Tests
**Current Coverage**: None (acceptable)

**Optional Tests** (if performance is critical):

```php
#[Test]
public function testStreamingMemoryUsage(): void
{
    $before = memory_get_usage();
    $reader->read('large-file.jpg'); // 500MB
    $after = memory_get_usage();
    
    $delta = $after - $before;
    self::assertLessThan(10 * 1024 * 1024, $delta); // < 10MB
}

#[Test]
public function testParsingSpeed(): void
{
    $start = microtime(true);
    $reader->read('test-file.jpg');
    $elapsed = microtime(true) - $start;
    
    self::assertLessThan(0.1, $elapsed); // < 100ms
}
```

**Recommendation**: Low priority unless performance issues arise

---

## 5. Test Data Strategy

### Current Approach
- Some real-world files in fixtures (if any)
- Synthetic data created in tests

### Recommended Approach

#### 5.1 Synthetic Test Files
**Why**: Small, focused, version-controlled

**Create Helper**:
```php
// tests/TestDataFactory.php
final class TestDataFactory
{
    public static function createMinimalExif(array $tags): string
    {
        // Create smallest valid EXIF blob with given tags
        // Returns hex string for stream creation
    }
    
    public static function createTiffHeader(
        bool $bigTiff = false,
        Endianness $endian = Endianness::II
    ): string
    {
        // Create TIFF header only
    }
    
    public static function createIfdEntry(
        int $tag,
        int $type,
        mixed $value
    ): string
    {
        // Create single IFD entry
    }
}
```

**Usage**:
```php
#[Test]
public function testImageWidth(): void
{
    $exif = TestDataFactory::createMinimalExif([
        TiffTag::IMAGE_WIDTH => 1920,
        TiffTag::IMAGE_LENGTH => 1080,
    ]);
    
    $stream = StreamFactory::fromString($exif);
    $parsed = $parser->parse($stream);
    
    self::assertSame(1920, $parsed->imageWidth());
}
```

#### 5.2 Real-World Test Files (Optional)
**Considerations**:
- Copyright/licensing
- File size (keep small)
- Format diversity

**Recommended Sources**:
1. **Public domain images**: Wikimedia Commons
2. **Generated samples**: Use camera SDKs to create minimal files
3. **Synthetic**: Tools like `exiftool` to craft specific scenarios

**Strategy**:
- Keep test files < 100KB each
- Strip unnecessary data
- Document source and license

---

## 6. Test Coverage Metrics

### Measurement
```bash
# Run with coverage
composer ci:test:php:unit:coverage

# Check HTML report
open .build/coverage/index.html
```

### Target Metrics
| Component | Current | Target | Priority |
|-----------|---------|--------|----------|
| Value Objects | ~90% | 95% | Low |
| Parsers | ~70% | 90% | High |
| Model Layer | ~80% | 90% | Medium |
| Error Paths | ~50% | 85% | High |
| Integration | ~20% | 70% | Medium |
| **Overall** | **~80%** | **90%** | **High** |

---

## 7. Recommended Test Additions

### Priority 1 (High Impact)
1. **TiffExifReaderEdgeCaseTest.php** (20 tests)
   - Circular references
   - Invalid offsets
   - Corrupt entries
   - BigTIFF edge cases

2. **ErrorHandlingTest.php** (15 tests)
   - Truncated files
   - Invalid data types
   - Encoding errors
   - Graceful degradation

3. **Exif30FeatureTest.php** (10 tests)
   - Version detection
   - BigTIFF vs Classic
   - PreviewIFD (when implemented)

### Priority 2 (Medium Impact)
4. **ParsedExifNewGettersTest.php** (30 tests)
   - Test all new getters added
   - Missing value scenarios
   - Type consistency

5. **IntegrationTest.php** (10 tests)
   - Real-world formats
   - Multi-format combinations
   - End-to-end workflows

### Priority 3 (Nice to Have)
6. **PerformanceTest.php** (5 tests)
   - Memory usage
   - Parsing speed
   - Large file handling

7. **RegressionTest.php** (as needed)
   - Tests for fixed bugs
   - Prevents regressions

---

## 8. Test Writing Guidelines

### Template for New Tests
```php
<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\[Category];

use MagicSunday\ImageMeta\[ClassUnderTest];
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for [ClassUnderTest].
 *
 * @see [EXIF/TIFF spec reference]
 */
final class [ClassName]Test extends TestCase
{
    #[Test]
    public function testBasicFunctionality(): void
    {
        // Arrange
        $input = ...;
        
        // Act
        $result = ...;
        
        // Assert
        self::assertSame($expected, $result);
    }
    
    #[Test]
    public function testEdgeCase(): void
    {
        // Test boundary conditions
    }
    
    #[Test]
    public function testErrorHandling(): void
    {
        $this->expectException(ParseError::class);
        // Test error path
    }
}
```

### Naming Conventions
- Test files: `*Test.php`
- Test methods: `test[MethodName][Scenario]()`
- Mock helpers: `createMock[Entity]()`

### Best Practices
1. ✅ Use PHPUnit 12 attributes (`#[Test]`)
2. ✅ One assertion per test (when possible)
3. ✅ Test both positive and negative paths
4. ✅ Use descriptive test names
5. ✅ Add spec references in docblocks
6. ✅ Keep tests focused and small
7. ✅ Use synthetic data when possible

---

## 9. Coverage Improvement Plan

### Week 1: Parser Edge Cases
- [ ] Create TiffExifReaderEdgeCaseTest.php
- [ ] Add 20 edge case tests
- [ ] Run coverage: expect +5%

### Week 2: Error Handling
- [ ] Create ErrorHandlingTest.php
- [ ] Add 15 error handling tests
- [ ] Run coverage: expect +5%

### Week 3: EXIF 3.0 & New Features
- [ ] Create Exif30FeatureTest.php
- [ ] Add tests for new getters
- [ ] Run coverage: expect +5%

### Week 4: Integration & Polish
- [ ] Create integration tests
- [ ] Fill remaining gaps
- [ ] Achieve 90%+ coverage

---

## 10. Continuous Improvement

### CI Integration
Ensure tests run on every commit:
```yaml
# .github/workflows/ci.yml
- name: Run Tests with Coverage
  run: composer ci:test:php:unit:coverage

- name: Check Coverage Threshold
  run: |
    coverage=$(php scripts/check-coverage.php)
    if [ $coverage -lt 90 ]; then
      echo "Coverage below 90%: $coverage"
      exit 1
    fi
```

### Coverage Monitoring
- Track coverage over time
- Fail builds if coverage drops
- Celebrate when hitting targets!

---

## Conclusion

The ImageMeta library has a **solid test foundation** but needs focused effort on:
1. **Parser edge cases** (highest priority)
2. **Error handling** (security critical)
3. **EXIF 3.0 features** (compliance)
4. **Integration tests** (real-world validation)

With the recommended test additions (~90 new tests), the library can achieve the **90%+ coverage target** while ensuring robust handling of edge cases and errors.

---

**Next Steps**:
1. Review and approve test plan
2. Create test data factory helper
3. Begin Priority 1 test implementation
4. Run coverage after each phase
5. Adjust plan based on findings

**Estimated Effort**: 4-5 days for all recommended tests
