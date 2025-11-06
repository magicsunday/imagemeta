# Final Tag Reorganization TODO

## Current Status

✅ **Phase 1 COMPLETE** (Commit 62715f8):
- PREVIEW_IMAGE_* tags moved from DngTag to LegacyTag
- All code and test references updated

## Remaining Work Per User Request (Comment #3499545701)

### Requirements

1. **DngTag.php** - Only official DNG 1.7 specification tags (docs/DNG_Spec_1_7_1_0.pdf)
2. **TiffTag.php** - Only TIFF 6.0 Appendix A tags (docs/TIFF6.pdf) that are NOT in EXIF list
3. **ExifTag.php** - Only exact 154 EXIF 3.0 tags from user's list
4. **LegacyTag.php** - Everything else

### Tag Distribution Analysis

**EXIF Tags** (154 from user's list):
- These include shared TIFF/EXIF tags like: Artist, DateTime, Make, Model, Orientation, Software, etc.
- These shared tags should STAY in ExifTag (per user's explicit EXIF list)

**TIFF-Only Tags** (TIFF 6.0 tags NOT in EXIF list):
- Examples: NewSubfileType (254), SubfileType (255), DocumentName (269), HostComputer (316), Predictor (317), TileWidth (322), TileLength (323), TileOffsets (324), TileByteCounts (325), InkSet (332), JPEGProc (512), etc.
- These should be in TiffTag.php

**Current Issue**:
- TiffTag has 22 constants, needs verification against TIFF 6.0 Appendix A (74 tags total, minus those in EXIF)
- ExifTag needs verification against user's exact 154-tag list
- Some tags might be in wrong classes

### Implementation Plan

1. **Generate Master Tag List**
   - Parse all constants from ExifTag, TiffTag, LegacyTag, DngTag, MicrosoftXpTag
   - Compare against user's lists

2. **Identify Misplaced Tags**
   - Tags in ExifTag but NOT in user's EXIF list → move to TiffTag or LegacyTag
   - Tags in TiffTag but IN user's EXIF list → move to ExifTag
   - TIFF tags not in either list → keep in TiffTag if valid TIFF 6.0

3. **Update All References**
   - TiffExifReader.php
   - ParsedExif.php
   - All test files
   - Value converters and other code

4. **Validate**
   - Run tests
   - Check PHPStan
   - Verify all tag IDs match specifications

### Estimated Scope

- ~200+ tag constants to review
- ~50+ code references to update
- ~20+ test assertions to update
- Multiple commits for safety

### Current State Files

**Tag Classes:**
- `src/Model/Exif/ExifTag.php` - 163 constants (needs verification against 154-tag list)
- `src/Model/Tiff/TiffTag.php` - 22 constants (needs expansion/verification)
- `src/Model/Dng/DngTag.php` - ~20 constants (verified correct)
- `src/Model/Microsoft/MicrosoftXpTag.php` - 5 constants (correct)
- `src/Model/Legacy/LegacyTag.php` - ~23 constants (will grow)

**Code Using Tags:**
- `src/Parse/Tiff/TiffExifReader.php`
- `src/Model/Exif/ParsedExif.php`
- `tests/Model/Exif/ExifTagSourcesTest.php`
- Various other test and implementation files

## Next Steps

Given the scope (200+ tags, multiple files, breaking changes), this work should be:
1. Done carefully with specification references
2. Split into logical commits
3. Fully tested at each step
4. Documented with migration guide

**Recommendation**: Complete this in a follow-up focused refactoring session with adequate time for testing.
