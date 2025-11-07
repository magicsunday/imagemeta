# Quick Reference - EXIF Compliance Checklist

**Current Status**: 75.46% compliance (123/163 tags)  
**Target**: 90%+ compliance  
**Use this checklist to track progress through the improvement plan**

---

## ✅ Phase 1: Critical Fixes (Day 1)

### Missing Getter for Required Tag
- [ ] Add `imageLength()` getter to `src/Model/Exif/ParsedExif.php`
  - Tag: 0x0101 (ImageLength)
  - Type: `int|null`
  - Source: TIFF 6.0 p.36
  - Test: Add to ParsedExifTest.php
- [ ] Run tests: `composer ci:test:php:unit`

### Add Missing Constants

#### ExifTag.php (PreviewIFD - EXIF 3.0)
- [ ] `PREVIEW_IMAGE_START = 0xC51B`
- [ ] `PREVIEW_IMAGE_LENGTH = 0xC51C`
- [ ] `PREVIEW_IMAGE_ENCODING = 0xC51D`
- [ ] `PREVIEW_IMAGE_MIME_TYPE = 0xC51E`
- [ ] `PREVIEW_IMAGE_BIT_DEPTH = 0xC522`
- [ ] `PREVIEW_IMAGE_SCALE = 0xC62F`

#### ExifTag.php (InteropIFD)
- [ ] `RELATED_IMAGE_FILE_FORMAT = 0x1000`
- [ ] `RELATED_IMAGE_LENGTH = 0x1002`

#### TiffTag.php (TIFF Baseline)
- [ ] `NEW_SUBFILE_TYPE = 0x00FE`
- [ ] `DOCUMENT_NAME = 0x010D`
- [ ] `HOST_COMPUTER = 0x013C`
- [ ] `TILE_WIDTH = 0x0142`
- [ ] `TILE_LENGTH = 0x0143`
- [ ] `TILE_OFFSETS = 0x0144`
- [ ] `TILE_BYTE_COUNTS = 0x0145`

### Verification
- [ ] Run compliance check: `php scripts/analyze-exif-compliance.php`
- [ ] Expected coverage: ~77% (up from 75.46%)
- [ ] Commit changes with conventional commit message

---

## ✅ Phase 2: Simple Getters (Day 2)

### TIFF Metadata Tags
- [ ] `documentName()` → string|null (TiffTag::DOCUMENT_NAME)
- [ ] `hostComputer()` → string|null (TiffTag::HOST_COMPUTER)
- [ ] `newSubfileType()` → int|null (TiffTag::NEW_SUBFILE_TYPE)

### InteropIFD Tags
- [ ] `relatedImageFileFormat()` → string|null (ExifTag::RELATED_IMAGE_FILE_FORMAT)
- [ ] `relatedImageLength()` → int|null (ExifTag::RELATED_IMAGE_LENGTH)

### Tests for Each Getter
- [ ] Test with value present
- [ ] Test with value missing (null)
- [ ] Run tests: `composer ci:test:php:unit`

### Verification
- [ ] Run compliance check
- [ ] Expected coverage: ~80%
- [ ] Commit changes

---

## ✅ Phase 3: Tile-Based TIFF (Day 3)

### Tile Tag Getters
- [ ] `tileWidth()` → int|null (TiffTag::TILE_WIDTH)
- [ ] `tileLength()` → int|null (TiffTag::TILE_LENGTH)
- [ ] `tileOffsets()` → array<int>|null (TiffTag::TILE_OFFSETS)
- [ ] `tileByteCounts()` → array<int>|null (TiffTag::TILE_BYTE_COUNTS)

### Tests
- [ ] Single-tile image test
- [ ] Multi-tile image test
- [ ] Non-tiled image test (null)
- [ ] Array length consistency test

### Verification
- [ ] Run compliance check
- [ ] Expected coverage: ~82%
- [ ] Commit changes

---

## ✅ Phase 4: Complex Tags (Days 4-5)

### Simple Int/Array Tags (Day 4 AM)
- [ ] `subIFDs()` → array|null
- [ ] `sensitivityType()` → int|null
- [ ] `recommendedExposureIndex()` → int|null
- [ ] `isoSpeed()` → int|null
- [ ] `isoSpeedLatitudeYyy()` → int|null
- [ ] `isoSpeedLatitudeZzz()` → int|null
- [ ] `temperature()` → float|null

### Complex Tags - Create VOs (Day 4 PM)
- [ ] Design TransferFunction VO
- [ ] Design ColorimetryData VO (white point, chromaticities)
- [ ] Design CfaPattern VO
- [ ] Design SpectralResponse VO

### Complex Tags - Implement Getters (Day 5)
- [ ] `transferFunction()` → TransferFunction|null
- [ ] `whitePoint()` → array|null
- [ ] `primaryChromaticities()` → array|null
- [ ] `yCbCrCoefficients()` → array|null
- [ ] `referenceBlackWhite()` → array|null
- [ ] `cfaRepeatPatternDim()` → array|null
- [ ] `cfaPattern()` → CfaPattern|null
- [ ] `spatialFrequencyResponse()` → array|null
- [ ] `spectralSensitivity()` → string|null
- [ ] `oecf()` → array|null
- [ ] `deviceSettingDescription()` → string|null

### Tests
- [ ] Unit tests for all new getters
- [ ] Complex VO tests
- [ ] Integration tests

### Verification
- [ ] Run compliance check
- [ ] Expected coverage: ~88-90%
- [ ] Commit changes

---

## ✅ Phase 5: EXIF 3.0 PreviewIFD (Days 6-8)

### Day 6: Architecture
- [ ] Design `PreviewImage` value object
  - Properties: start, length, encoding, mimeType, bitDepth, scale
  - Method: `isAvailable()`
- [ ] Create `PreviewImageEncoding` enum
  - Cases: JPEG, TIFF, PNG, etc.
- [ ] Update `ParsedExif` constructor to accept `?Ifd $previewIfd`
- [ ] Document design decisions

### Day 7: Parser Integration
- [ ] Update `TiffExifReader::parse()` to detect PreviewIFD
- [ ] Implement `parsePreviewIfd()` method
- [ ] Handle IFD chain properly (IFD0 → PreviewIFD)
- [ ] Add bounds checking for preview offsets
- [ ] Test parser changes

### Day 8: Getters & Tests
- [ ] Implement `previewImage()` getter in ParsedExif
- [ ] Create helper method `previewImageEncoding()`
- [ ] Write comprehensive tests:
  - [ ] PreviewIFD present with all fields
  - [ ] PreviewIFD present with partial fields
  - [ ] PreviewIFD missing
  - [ ] Invalid preview offsets
- [ ] Test with real EXIF 3.0 files (if available)
- [ ] Update documentation

### Verification
- [ ] Run compliance check
- [ ] Expected coverage: ~95%
- [ ] Run full test suite
- [ ] Commit changes

---

## ✅ Phase 6: Documentation (Days 9-10)

### Add Spec References to Code (Day 9)
- [ ] `src/Parse/Tiff/TiffExifReader.php`
  - [ ] Add TIFF 6.0 section refs to parsing methods
  - [ ] Add EXIF 3.0 section refs to IFD handling
  - [ ] Document BigTIFF vs Classic TIFF differences
- [ ] `src/Model/Exif/ValueConverters.php`
  - [ ] Add formula sources for conversions
  - [ ] Reference EXIF sections for algorithms
- [ ] `src/Parse/IsoBmff/IsoBmffExtractor.php`
  - [ ] Add ISO 14496 references where applicable

### Create Missing Enums (Day 9)
- [ ] Create `src/Value/Enum/IfdKind.php`
  - Cases: IFD0, ExifIFD, GPSIFD, InteropIFD, PreviewIFD
- [ ] Create `src/Value/Enum/ExifType.php`
  - Cases: BYTE, ASCII, SHORT, LONG, RATIONAL, etc.
  - Include BigTIFF types (LONG8, SLONG8, IFD8)
- [ ] Update code to use new enums where applicable

### Update Documentation (Day 10)
- [ ] Update `README.md`
  - [ ] Update EXIF version badges
  - [ ] Note PreviewIFD support
  - [ ] Update feature list
- [ ] Update `COMPLIANCE.md`
  - [ ] New coverage statistics
  - [ ] Update category breakdowns
  - [ ] Add PreviewIFD section
- [ ] Update `CHANGELOG.md`
  - [ ] Add entry for compliance improvements
  - [ ] List new tags/features

### Verification
- [ ] All docs up-to-date
- [ ] Examples in docs work
- [ ] Commit changes

---

## ✅ Phase 7: Test Coverage (Days 11-12)

### Parser Edge Cases (Day 11 AM)
Create `tests/Parse/Tiff/TiffExifReaderEdgeCaseTest.php`:
- [ ] Test circular IFD references
- [ ] Test invalid IFD offsets (beyond file size)
- [ ] Test excessive entry counts
- [ ] Test truncated IFD data
- [ ] Test BigTIFF large offsets (> 4GB)
- [ ] Test Classic vs BigTIFF marker detection
- [ ] Test endianness handling
- [ ] Test corrupt entry data

### Error Handling (Day 11 PM)
Create `tests/Parse/ErrorHandlingTest.php`:
- [ ] Test truncated JPEG files
- [ ] Test truncated EXIF data
- [ ] Test invalid tag types
- [ ] Test invalid rational (denominator = 0)
- [ ] Test string encoding errors
- [ ] Test null terminator missing
- [ ] Test malformed XMP XML
- [ ] Test buffer overruns

### EXIF 3.0 Features (Day 12 AM)
Create `tests/Parse/Tiff/Exif30FeatureTest.php`:
- [ ] Test EXIF version detection (2.1, 2.2, 2.3, 2.32, 3.0)
- [ ] Test BigTIFF vs Classic TIFF
- [ ] Test PreviewIFD parsing
- [ ] Test PreviewImage VO
- [ ] Test preview image extraction

### Integration Tests (Day 12 PM)
Create `tests/Integration/FormatTest.php`:
- [ ] Test real JPEG with EXIF
- [ ] Test HEIC with EXIF 3.0
- [ ] Test TIFF (Classic)
- [ ] Test BigTIFF (synthetic)
- [ ] Test tiled TIFF
- [ ] Test MP4/MOV with QuickTime metadata
- [ ] Test multi-format combinations

### Coverage Verification
- [ ] Run: `composer ci:test:php:unit:coverage`
- [ ] Check HTML report: `.build/coverage/index.html`
- [ ] Target: 90%+ overall coverage
- [ ] Identify any remaining gaps
- [ ] Add tests to fill gaps

### Final Checks
- [ ] All tests pass: `composer ci:test`
- [ ] PHPStan clean: `composer ci:test:php:phpstan`
- [ ] Code style clean: `composer ci:test:php:cgl`
- [ ] Compliance check: `php scripts/analyze-exif-compliance.php`
- [ ] Expected: 95%+ compliance
- [ ] Commit final changes

---

## ✅ Final Verification

### Compliance Check
- [ ] Run: `php scripts/analyze-exif-compliance.php`
- [ ] Verify: **Implemented** ≥ 155 tags (95%+)
- [ ] Verify: **Partial** = 0
- [ ] Verify: **Missing** ≤ 8 (only deprecated/rare tags)

### Test Coverage
- [ ] Run: `composer ci:test:php:unit:coverage`
- [ ] Verify: Overall coverage ≥ 90%
- [ ] Verify: Parser coverage ≥ 85%
- [ ] Verify: Model coverage ≥ 90%

### Code Quality
- [ ] PHPStan: No errors
- [ ] PHPCS: No errors
- [ ] CPD: No problematic duplicates
- [ ] All tests pass

### Documentation
- [ ] README.md updated
- [ ] COMPLIANCE.md updated
- [ ] CHANGELOG.md updated
- [ ] All analysis docs reviewed
- [ ] Code has spec references

---

## ✅ Project Completion

### Success Criteria
- [ ] Compliance ≥ 95% (target: 155+/163 tags)
- [ ] Test coverage ≥ 90%
- [ ] All quality checks pass
- [ ] Documentation complete
- [ ] No known bugs or issues

### Deliverables
- [ ] Functional code implementing 30+ new getters
- [ ] EXIF 3.0 PreviewIFD support
- [ ] 90+ new unit/integration tests
- [ ] Complete spec reference documentation
- [ ] Updated compliance reports
- [ ] Missing enums created

### Sign-Off
- [ ] Code review complete
- [ ] All tests passing in CI
- [ ] Documentation reviewed
- [ ] Ready for release

---

## Quick Commands Reference

```bash
# Run compliance analyzer
php scripts/analyze-exif-compliance.php

# Run unit tests
composer ci:test:php:unit

# Run tests with coverage
composer ci:test:php:unit:coverage

# View coverage report
open .build/coverage/index.html

# Run static analysis
composer ci:test:php:phpstan

# Check code style
composer ci:test:php:cgl

# Fix code style
composer ci:cgl

# Run all checks
composer ci:test

# Check for duplicates
composer ci:test:php:cpd
# OR
npx jscpd --config .build/.jscpd.json
```

---

## Progress Tracking

Track your progress by marking completed items with `[x]`.

**Start Date**: _____________  
**Target Completion**: _____________ (Start + 12 days)

**Current Phase**: _____________  
**Current Coverage**: _____________  
**Blockers**: _____________

---

## Notes

Use this space to track issues, decisions, or questions:

```
Date: _______
Phase: _______
Issue: _______
Resolution: _______

Date: _______
Phase: _______
Issue: _______
Resolution: _______
```

---

**Last Updated**: 2025-11-07  
**Checklist Version**: 1.0  
**For detailed information, see**:
- EXECUTIVE-SUMMARY.md
- EXIF-IMPLEMENTATION-ANALYSIS.md
- IMPLEMENTATION-ROADMAP.md
- TEST-COVERAGE-ANALYSIS.md
