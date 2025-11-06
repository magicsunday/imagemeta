# EXIF/TIFF Compliance - Tags Requiring Implementation

This document lists all tags that are currently only **partially implemented** and need to be completed.

**Last Updated**: 2025-11-06  
**Total Partial Tags**: 19/163 (11.7%)

## How to Use This Document

Each tag listed below needs a public getter method added to `src/Model/Exif/ParsedExif.php` to be considered fully implemented.

**Steps to complete a tag**:
1. Verify the tag constant exists in `src/Model/Exif/ExifTag.php`
2. Add a public getter method to `src/Model/Exif/ParsedExif.php`
3. Add appropriate tests
4. Re-run compliance analyzer to verify: `composer ci:compliance`

---

## TIFF 6.0 Baseline Tags (7 partial)

### 1. NewSubfileType (0x00FE)
- **IFD**: IFD0
- **Type**: LONG
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Subfile type indicator
- **Action**: Add `public function newSubfileType(): ?int` to ParsedExif

### 2. SubfileType (0x00FF) 
- **IFD**: IFD0
- **Type**: SHORT
- **Source**: TIFF 5.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: **DEPRECATED** - Legacy subfile type
- **Action**: Optional - deprecated tag, low priority

### 3. JPEGInterchangeFormat (0x0201)
- **IFD**: IFD1
- **Type**: LONG
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Offset to JPEG SOI marker
- **Action**: Already available via `jpegThumbnailOffset()` - may need alias or mapping fix

### 4. JPEGInterchangeFormatLength (0x0202)
- **IFD**: IFD1
- **Type**: LONG
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Bytes of JPEG data
- **Action**: Already available via `jpegThumbnailLength()` - may need alias or mapping fix

### 5. YCbCrCoefficients (0x0211)
- **IFD**: IFD0
- **Type**: RATIONAL
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Matrix coefficients for RGB to YCbCr
- **Action**: Add `public function ycbcrCoefficients(): ?array` to ParsedExif

### 6. YCbCrSubSampling (0x0212)
- **IFD**: IFD0
- **Type**: SHORT
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Subsampling ratio of Y to Cb/Cr
- **Action**: Add `public function ycbcrSubSampling(): ?array` to ParsedExif

### 7. YCbCrPositioning (0x0213)
- **IFD**: IFD0
- **Type**: SHORT
- **Source**: TIFF 6.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Positioning of subsampled chrominance
- **Action**: Add `public function ycbcrPositioning(): ?YCbCrPositioning` to ParsedExif (enum exists)

---

## EXIF Tags (10 partial)

### 1. FNumber (0x829D)
- **IFD**: ExifIFD
- **Type**: RATIONAL
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: F number
- **Action**: Add `public function fNumber(): ?float` to ParsedExif

### 2. PhotographicSensitivity (0x8827)
- **IFD**: ExifIFD
- **Type**: SHORT
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: ISO speed rating
- **Action**: May already be available via `iso()` - check mapping

### 3. SensitivityType (0x8830)
- **IFD**: ExifIFD
- **Type**: SHORT
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Type of sensitivity value
- **Action**: May already be available via `iso()` - check mapping

### 4. StandardOutputSensitivity (0x8831)
- **IFD**: ExifIFD
- **Type**: LONG
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Standard output sensitivity
- **Action**: May already be available via `iso()` - check mapping

### 5. PixelXDimension (0xA002)
- **IFD**: ExifIFD
- **Type**: SHORT|LONG
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Valid image width
- **Action**: May already be available via `imageWidth()` - check mapping

### 6. PixelYDimension (0xA003)
- **IFD**: ExifIFD
- **Type**: SHORT|LONG
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Valid image height
- **Action**: May already be available via `imageHeight()` - check mapping

### 7. CFAPattern (0xA302)
- **IFD**: ExifIFD
- **Type**: UNDEFINED
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: CFA pattern
- **Action**: Add `public function cfaPattern(): ?array` to ParsedExif (related method `cfaPatternDecoded()` may exist)

### 8. FocalLengthIn35mmFilm (0xA405)
- **IFD**: ExifIFD
- **Type**: SHORT
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Focal length in 35mm equivalent
- **Action**: May already be available via `focalLength35Mm()` - check mapping

### 9. PreviewImageStart (0xC51B) - EXIF 3.0
- **IFD**: PreviewIFD
- **Type**: LONG|IFD
- **Source**: EXIF 3.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Preview image start offset
- **Action**: May already be available via `previewImageOffset()` - check mapping

### 10. PreviewImageMIMEType (0xC51E) - EXIF 3.0
- **IFD**: PreviewIFD
- **Type**: ASCII
- **Source**: EXIF 3.0
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Preview image MIME type
- **Action**: Add `public function previewImageMimeType(): ?string` to ParsedExif

---

## Interoperability Tags (2 partial)

### 1. InteroperabilityIndex (0x0001)
- **IFD**: InteropIFD
- **Type**: ASCII
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Interoperability identification
- **Required**: Yes
- **Action**: May already be available via `interopIndex()` - check mapping

### 2. InteroperabilityVersion (0x0002)
- **IFD**: InteropIFD
- **Type**: UNDEFINED
- **Source**: EXIF 2.32
- **Status**: Constant defined ✓ | Getter method ✗
- **Notes**: Interoperability version
- **Action**: May already be available via `interopVersion()` - check mapping

---

## Summary by Priority

### High Priority (Required Tags)
- InteroperabilityIndex (required by spec)

### Medium Priority (Common Tags)
- FNumber
- PixelXDimension / PixelYDimension
- FocalLengthIn35mmFilm
- YCbCr related tags (for color space conversion)

### Low Priority
- SubfileType (deprecated)
- Preview-related tags (EXIF 3.0 only)

### Likely Already Implemented (Need Method Name Mapping)
Many of these tags likely already have getter methods with slightly different names:
- `PhotographicSensitivity` → `iso()`
- `PixelXDimension` → `imageWidth()`
- `PixelYDimension` → `imageHeight()`
- `FocalLengthIn35mmFilm` → `focalLength35Mm()`
- `PreviewImageStart` → `previewImageOffset()`
- `JPEGInterchangeFormat` → `jpegThumbnailOffset()`
- `JPEGInterchangeFormatLength` → `jpegThumbnailLength()`
- `InteroperabilityIndex` → `interopIndex()`
- `InteroperabilityVersion` → `interopVersion()`

**Recommendation**: Check if these methods exist and either:
1. Add alias methods with spec-compliant names
2. Update the analyzer to recognize common name variations
3. Document the mapping in code comments

---

## Progress Tracking

- [ ] TIFF Tags: 7 partial → 0 partial
- [ ] EXIF Tags: 10 partial → 0 partial
- [ ] Interop Tags: 2 partial → 0 partial

**Target**: 100% compliance (163/163 tags fully implemented)
