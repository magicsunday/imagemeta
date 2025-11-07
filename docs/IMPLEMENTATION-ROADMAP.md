# Implementation Roadmap - EXIF Compliance Improvements

**Target Coverage**: 90%+  
**Current Coverage**: 75.46%  
**Estimated Total Effort**: 8-12 days

---

## Quick Wins (Day 1)

### 1. Add ImageLength Getter ⚠️ HIGH PRIORITY
**Why**: Required TIFF 6.0 tag, constant exists but no getter  
**Effort**: 15 minutes  
**Files**: `src/Model/Exif/ParsedExif.php`, `tests/Model/Exif/ParsedExifTest.php`

```php
/**
 * Returns the image height in pixels.
 *
 * @see TIFF 6.0 p.36, EXIF 3.0 §4.6.4
 * @return int|null Image height or null if not present
 */
public function imageLength(): ?int
{
    return $this->int($this->ifd0, TiffTag::IMAGE_LENGTH);
}
```

**Test**:
```php
#[Test]
public function testImageLength(): void
{
    // Test with value present
    // Test with value missing
}
```

---

### 2. Add Missing Tag Constants
**Why**: Enables future implementation, improves compliance score  
**Effort**: 30 minutes  
**Files**: `src/Model/Exif/ExifTag.php`, `src/Model/Tiff/TiffTag.php`

#### ExifTag.php additions:
```php
// EXIF 3.0 PreviewIFD tags
// @see EXIF 3.0 §4.6.8, Table H.6

/**
 * Offset to preview image data in PreviewIFD.
 */
public const int PREVIEW_IMAGE_START = 0xC51B;

/**
 * Length of preview image data in bytes.
 */
public const int PREVIEW_IMAGE_LENGTH = 0xC51C;

/**
 * Encoding format of preview image (JPEG, TIFF, etc.).
 */
public const int PREVIEW_IMAGE_ENCODING = 0xC51D;

/**
 * MIME type of preview image.
 */
public const int PREVIEW_IMAGE_MIME_TYPE = 0xC51E;

/**
 * Bit depth per sample of preview image.
 */
public const int PREVIEW_IMAGE_BIT_DEPTH = 0xC522;

/**
 * Scale factor for preview image dimensions.
 */
public const int PREVIEW_IMAGE_SCALE = 0xC62F;

// InteropIFD tags
// @see EXIF 3.0 §4.6.7, Table H.5

/**
 * File format of related image (ASCII).
 */
public const int RELATED_IMAGE_FILE_FORMAT = 0x1000;

/**
 * Height of related image in pixels.
 */
public const int RELATED_IMAGE_LENGTH = 0x1002;
```

#### TiffTag.php additions:
```php
/**
 * Subfile type indicator (modern replacement for SubfileType).
 * 
 * @see TIFF 6.0 p.35
 */
public const int NEW_SUBFILE_TYPE = 0x00FE;

/**
 * Name of document from which image was scanned.
 * 
 * @see TIFF 6.0 p.37
 */
public const int DOCUMENT_NAME = 0x010D;

/**
 * Computer and/or OS used to create the image.
 * 
 * @see TIFF 6.0 p.38
 */
public const int HOST_COMPUTER = 0x013C;

/**
 * Tile width in pixels (alternative to strip-based layout).
 * 
 * @see TIFF 6.0 p.67
 */
public const int TILE_WIDTH = 0x0142;

/**
 * Tile height in pixels.
 * 
 * @see TIFF 6.0 p.67
 */
public const int TILE_LENGTH = 0x0143;

/**
 * Offsets to tile data blocks.
 * 
 * @see TIFF 6.0 p.68
 */
public const int TILE_OFFSETS = 0x0144;

/**
 * Byte counts for each tile.
 * 
 * @see TIFF 6.0 p.68
 */
public const int TILE_BYTE_COUNTS = 0x0145;
```

---

### 3. Run Compliance Check
**Effort**: 2 minutes  
```bash
php scripts/analyze-exif-compliance.php
```

**Expected Results After Day 1**:
- Coverage: ~77% (was 75.46%)
- Missing: 10 → 2 (only deprecated tags)
- Partial: 30 → 27 (constants added)

---

## Phase 2: Simple Getters (Day 2)

### 4. Implement Basic Tag Getters
**Why**: Low-hanging fruit, simple string/int getters  
**Effort**: 2-3 hours  
**Files**: `src/Model/Exif/ParsedExif.php`, tests

#### Priority Order:
1. **documentName()** - TIFF metadata
2. **hostComputer()** - TIFF metadata
3. **relatedImageFileFormat()** - InteropIFD
4. **relatedImageLength()** - InteropIFD
5. **newSubfileType()** - TIFF multi-page

#### Template:
```php
/**
 * Returns the [description].
 *
 * @see [SPEC] [section]
 * @return [type]|null Value or null if not present
 */
public function [methodName](): ?[type]
{
    return $this->[type]($this->[ifd], [Tag]::[CONSTANT]);
}
```

#### Examples:
```php
/**
 * Returns the name of the document from which this image was scanned.
 *
 * @see TIFF 6.0 p.37
 * @return string|null Document name or null if not present
 */
public function documentName(): ?string
{
    return $this->str($this->ifd0, TiffTag::DOCUMENT_NAME);
}

/**
 * Returns the computer/OS used to create the image.
 *
 * @see TIFF 6.0 p.38
 * @return string|null Host computer or null if not present
 */
public function hostComputer(): ?string
{
    return $this->str($this->ifd0, TiffTag::HOST_COMPUTER);
}

/**
 * Returns the file format of the related image (InteropIFD).
 *
 * @see EXIF 3.0 §4.6.7, Table H.5
 * @return string|null File format or null if not present
 */
public function relatedImageFileFormat(): ?string
{
    return $this->str($this->interopIfd, ExifTag::RELATED_IMAGE_FILE_FORMAT);
}

/**
 * Returns the height of the related image (InteropIFD).
 *
 * @see EXIF 3.0 §4.6.7, Table H.5
 * @return int|null Image length or null if not present
 */
public function relatedImageLength(): ?int
{
    return $this->int($this->interopIfd, ExifTag::RELATED_IMAGE_LENGTH);
}

/**
 * Returns the subfile type indicator.
 *
 * @see TIFF 6.0 p.35
 * @return int|null Subfile type or null if not present
 */
public function newSubfileType(): ?int
{
    return $this->int($this->ifd0, TiffTag::NEW_SUBFILE_TYPE);
}
```

**Tests** (for each getter):
```php
#[Test]
public function test[MethodName](): void
{
    // Test with value present
    $ifd = $this->createMockIfdWith[Tag]([VALUE]);
    $parsed = new ParsedExif($ifd, ...);
    self::assertSame([VALUE], $parsed->[methodName]());
}

#[Test]
public function test[MethodName]Missing(): void
{
    // Test with value absent
    $ifd = $this->createEmptyMockIfd();
    $parsed = new ParsedExif($ifd, ...);
    self::assertNull($parsed->[methodName]());
}
```

**Expected Results After Day 2**:
- Coverage: ~80% (5 tags moved from partial to implemented)
- Partial: 27 → 22

---

## Phase 3: Tile-Based TIFF Support (Day 3)

### 5. Implement Tile Tag Getters
**Why**: Needed for large images, GeoTIFF  
**Effort**: 3-4 hours  
**Complexity**: Medium (arrays, careful with memory)

#### Tags to Implement:
1. tileWidth()
2. tileLength()
3. tileOffsets()
4. tileByteCounts()

#### Implementation Notes:
- Tile tags return **arrays** (multiple tiles)
- Keep streaming architecture (don't load tile data)
- Add validation (tile dimensions must be positive)

```php
/**
 * Returns the tile width in pixels for tiled images.
 *
 * @see TIFF 6.0 p.67
 * @return int|null Tile width or null if not tiled
 */
public function tileWidth(): ?int
{
    return $this->int($this->ifd0, TiffTag::TILE_WIDTH);
}

/**
 * Returns the tile height in pixels for tiled images.
 *
 * @see TIFF 6.0 p.67
 * @return int|null Tile height or null if not tiled
 */
public function tileLength(): ?int
{
    return $this->int($this->ifd0, TiffTag::TILE_LENGTH);
}

/**
 * Returns offsets to tile data blocks.
 *
 * @see TIFF 6.0 p.68
 * @return array<int>|null Array of offsets or null if not tiled
 */
public function tileOffsets(): ?array
{
    $value = $this->raw($this->ifd0, TiffTag::TILE_OFFSETS);
    return is_array($value) ? $value : null;
}

/**
 * Returns byte counts for each tile.
 *
 * @see TIFF 6.0 p.68
 * @return array<int>|null Array of byte counts or null if not tiled
 */
public function tileByteCounts(): ?array
{
    $value = $this->raw($this->ifd0, TiffTag::TILE_BYTE_COUNTS);
    return is_array($value) ? $value : null;
}
```

**Tests**:
- Test with single-tile image
- Test with multi-tile image
- Test with non-tiled image (should return null)
- Test array length consistency (offsets count == byte counts count)

**Expected Results After Day 3**:
- Coverage: ~82% (4 more tags implemented)
- Partial: 22 → 18

---

## Phase 4: Complex Tags (Days 4-5)

### 6. Implement Remaining Partial Tags
**Why**: Achieve 90%+ coverage goal  
**Effort**: 1-2 days  
**Complexity**: Medium-High (some require value objects)

#### Remaining 18 Partial Tags (prioritized):

**Simple (int/array):**
1. subIFDs() - SubIFD offsets
2. sensitivityType() - ISO sensitivity type
3. recommendedExposureIndex() - REI
4. isoSpeed() - ISO speed
5. isoSpeedLatitudeYyy() - ISO latitude
6. isoSpeedLatitudeZzz() - ISO latitude
7. temperature() - Ambient temperature

**Complex (need conversion/VO):**
8. transferFunction() - Tone curve
9. whitePoint() - CIE white point
10. primaryChromaticities() - Color primaries
11. yCbCrCoefficients() - Color space coefficients
12. referenceBlackWhite() - Reference levels
13. cfaRepeatPatternDim() - CFA pattern dimensions
14. cfaPattern() - Color filter array pattern
15. spatialFrequencyResponse() - SFR table
16. spectralSensitivity() - Spectral response
17. oecf() - OECF curve
18. deviceSettingDescription() - Device settings

#### Strategy:
1. Implement simple getters first (Day 4 morning)
2. Create value objects for complex tags (Day 4 afternoon)
3. Implement complex getters (Day 5)
4. Write comprehensive tests (Day 5)

#### Example Complex Tag:
```php
/**
 * Returns the CFA (Color Filter Array) pattern.
 *
 * @see EXIF 3.0 §4.6.5.3
 * @return CfaPattern|null CFA pattern or null if not present
 */
public function cfaPattern(): ?CfaPattern
{
    $dim = $this->raw($this->exifIfd, ExifTag::CFA_REPEAT_PATTERN_DIM);
    $pattern = $this->raw($this->exifIfd, ExifTag::CFA_PATTERN);
    
    if ($dim === null || $pattern === null) {
        return null;
    }
    
    return new CfaPattern($dim, $pattern);
}
```

**Expected Results After Days 4-5**:
- Coverage: ~88-90%
- Partial: 18 → 0
- All tags either fully implemented or intentionally deferred

---

## Phase 5: EXIF 3.0 PreviewIFD (Days 6-8)

### 7. Full PreviewIFD Implementation
**Why**: Complete EXIF 3.0 support  
**Effort**: 3 days  
**Complexity**: High (requires parser changes)

#### Subtasks:

**Day 6: Architecture & Value Object**
1. Design PreviewImage value object
2. Update ParsedExif constructor to accept PreviewIFD
3. Create PreviewImageEncoding enum

```php
// src/Value/PreviewImage.php
final readonly class PreviewImage
{
    public function __construct(
        public ?int $start,
        public ?int $length,
        public ?PreviewImageEncoding $encoding,
        public ?string $mimeType,
        public ?int $bitDepth,
        public ?float $scale,
    ) {}
    
    /**
     * Returns whether preview data is available.
     */
    public function isAvailable(): bool
    {
        return $this->start !== null && $this->length !== null;
    }
}

// src/Value/Enum/PreviewImageEncoding.php
enum PreviewImageEncoding: int
{
    case JPEG = 1;
    case TIFF = 2;
    case PNG = 3;
    // Add more based on EXIF 3.0 spec
}
```

**Day 7: Parser Integration**
1. Update TiffExifReader to parse PreviewIFD
2. Add PreviewIFD to ParsedExif constructor
3. Handle IFD chain properly

```php
// src/Parse/Tiff/TiffExifReader.php
private function parsePreviewIfd(Stream $stream, int $offset): ?Ifd
{
    // Parse PreviewIFD similar to other IFDs
    // @see EXIF 3.0 §4.6.8
}
```

**Day 8: Getters & Tests**
1. Implement getter in ParsedExif
2. Write comprehensive tests
3. Test with real EXIF 3.0 files

```php
// src/Model/Exif/ParsedExif.php
/**
 * Returns preview image metadata if available.
 *
 * @see EXIF 3.0 §4.6.8
 * @return PreviewImage|null Preview metadata or null
 */
public function previewImage(): ?PreviewImage
{
    if ($this->previewIfd === null) {
        return null;
    }
    
    return new PreviewImage(
        start: $this->int($this->previewIfd, ExifTag::PREVIEW_IMAGE_START),
        length: $this->int($this->previewIfd, ExifTag::PREVIEW_IMAGE_LENGTH),
        encoding: $this->previewImageEncoding(),
        mimeType: $this->str($this->previewIfd, ExifTag::PREVIEW_IMAGE_MIME_TYPE),
        bitDepth: $this->int($this->previewIfd, ExifTag::PREVIEW_IMAGE_BIT_DEPTH),
        scale: $this->float($this->previewIfd, ExifTag::PREVIEW_IMAGE_SCALE),
    );
}
```

**Expected Results After Days 6-8**:
- Coverage: ~95%
- Missing: 10 → 2 (only deprecated tags)
- Full EXIF 3.0 support achieved

---

## Phase 6: Documentation & Polish (Days 9-10)

### 8. Add Spec References to Parser Code
**Why**: Maintainability, compliance verification  
**Effort**: 4-6 hours

#### Files to Update:
1. `src/Parse/Tiff/TiffExifReader.php`
2. `src/Model/Exif/ValueConverters.php`
3. `src/Parse/IsoBmff/IsoBmffExtractor.php`

**Example**:
```php
/**
 * Parses an IFD starting at the given offset.
 *
 * @see TIFF 6.0 §2.2: IFD Structure
 * @see EXIF 3.0 §4.6.2: TIFF Header
 */
private function parseIfd(Stream $stream, int $offset): Ifd
{
    // Implementation with inline spec references
    
    // TIFF 6.0 §2.2: Entry count is first 2 bytes (Classic) or 8 bytes (BigTIFF)
    $entryCount = $this->readEntryCount($stream);
    
    // TIFF 6.0 §2.2: Each entry is 12 bytes (Classic) or 20 bytes (BigTIFF)
    // ...
}
```

### 9. Update Documentation
**Effort**: 2 hours

#### Updates Required:
1. **README.md**: Update EXIF version badges
2. **COMPLIANCE.md**: Update with new stats
3. **CHANGELOG.md**: Add entry for compliance improvements
4. **EXIF-IMPLEMENTATION-ANALYSIS.md**: Mark phases as complete

### 10. Create Missing Enums
**Effort**: 2 hours

```php
// src/Value/Enum/IfdKind.php (from AGENTS.md)
enum IfdKind: string
{
    case IFD0 = 'IFD0';
    case ExifIFD = 'ExifIFD';
    case GPSIFD = 'GPSIFD';
    case InteropIFD = 'InteropIFD';
    case PreviewIFD = 'PreviewIFD';
}

// src/Value/Enum/ExifType.php (from AGENTS.md)
enum ExifType: int
{
    case BYTE = 1;
    case ASCII = 2;
    case SHORT = 3;
    case LONG = 4;
    case RATIONAL = 5;
    case SBYTE = 6;
    case UNDEFINED = 7;
    case SSHORT = 8;
    case SLONG = 9;
    case SRATIONAL = 10;
    case FLOAT = 11;
    case DOUBLE = 12;
    // BigTIFF
    case LONG8 = 16;
    case SLONG8 = 17;
    case IFD8 = 18;
}
```

---

## Phase 7: Test Coverage (Days 11-12)

### 11. Achieve 90%+ Test Coverage
**Effort**: 2 days

#### Focus Areas:

**Day 11: Unit Tests**
- Add tests for all new getters
- Edge cases for new functionality
- Negative tests (missing tags, null values)

**Day 12: Integration Tests**
- Test with diverse file formats
- EXIF 3.0 files with PreviewIFD
- BigTIFF files
- Tiled TIFF files
- Corrupt files (error handling)

#### Test File Requirements:
Create synthetic test files for:
- EXIF 3.0 with PreviewIFD
- Tiled TIFF (4x4 tiles)
- Multi-page TIFF
- BigTIFF (simulated offsets)

**Coverage Check**:
```bash
composer ci:test:php:unit:coverage
```

Target: 90%+ overall coverage

---

## Summary: Expected Outcomes

### Before (Current State):
- Coverage: 75.46%
- Implemented: 123
- Partial: 30
- Missing: 10
- Extra: 16

### After (Target State):
- Coverage: 95%+
- Implemented: 161+
- Partial: 0
- Missing: 2 (deprecated only)
- Extra: 16 (intentional)

### Deliverables:
1. ✅ All required tags implemented
2. ✅ EXIF 3.0 PreviewIFD support
3. ✅ Tile-based TIFF support
4. ✅ Comprehensive documentation with spec references
5. ✅ 90%+ test coverage
6. ✅ Missing enums created
7. ✅ Updated compliance reports

---

## Daily Checklist Template

### Day N: [Phase Name]

**Morning:**
- [ ] Review plan
- [ ] Identify files to modify
- [ ] Check existing tests

**Implementation:**
- [ ] Add constants/enums
- [ ] Implement getters/methods
- [ ] Write unit tests
- [ ] Run tests: `composer ci:test:php:unit`

**Quality Checks:**
- [ ] Run PHPStan: `composer ci:test:php:phpstan`
- [ ] Check style: `composer ci:cgl`
- [ ] Run compliance: `php scripts/analyze-exif-compliance.php`

**End of Day:**
- [ ] Commit changes
- [ ] Update progress document
- [ ] Note any blockers

---

## Risk Mitigation

### Potential Issues:

1. **Breaking Changes**: 
   - Risk: New methods conflict with existing code
   - Mitigation: Thorough testing, check existing usage

2. **Performance Regression**:
   - Risk: New parsing slows down metadata extraction
   - Mitigation: Profile before/after, optimize if needed

3. **Test Data Availability**:
   - Risk: Can't find EXIF 3.0 files with PreviewIFD
   - Mitigation: Create synthetic test files

4. **Spec Ambiguity**:
   - Risk: EXIF 3.0 spec unclear on PreviewIFD details
   - Mitigation: Reference multiple sources, check real-world files

---

## Success Criteria

Phase complete when:
- [ ] All tests pass
- [ ] Coverage ≥ 90%
- [ ] PHPStan passes (no errors)
- [ ] Code style passes
- [ ] Compliance report shows target coverage
- [ ] Documentation updated
- [ ] Code review complete (if applicable)

---

**Start Date**: TBD  
**Target Completion**: TBD + 12 days  
**Owner**: [Assign]  
**Reviewer**: [Assign]
