# EXIF Tag Organization

## Overview

Tag constants are organized by source specification to improve maintainability and clarity. This document explains the tag organization and how to use the various tag classes.

## Tag Classes by Source

### 1. ExifTag (Official EXIF 3.0 Standard)
**Location**: `src/Model/Exif/ExifTag.php`  
**Source**: EXIF 3.0 §H.6 Tables 64-67  
**Count**: ~120 official EXIF tags

Contains all official EXIF 3.0 specification tags from:
- Table 64: 0th IFD TIFF Tags (32 tags)
- Table 65: 0th IFD Exif Private Tags (77 tags)
- Table 66: GPS Info Tags (32 tags)
- Table 67: Interoperability Tags (1 tag)

**Usage**:
```php
use MagicSunday\ImageMeta\Model\Exif\ExifTag;

$tag = ExifTag::IMAGE_WIDTH;           // 0x0100
$tag = ExifTag::DATETIME_ORIGINAL;     // 0x9003
$tag = ExifTag::GPS_LATITUDE;          // 0x0002
```

### 2. TiffTag (TIFF 6.0 Baseline)
**Location**: `src/Model/Tiff/TiffTag.php`  
**Source**: TIFF 6.0 Specification  
**Count**: 24 tags

Contains TIFF 6.0 baseline tags that are NOT part of the EXIF specification:
- Subfile type tags (NEW_SUBFILE_TYPE, SUBFILE_TYPE)
- Tile-related tags (TILE_WIDTH, TILE_LENGTH, TILE_OFFSETS, TILE_BYTE_COUNTS)
- ICC color profile (ICC_PROFILE)
- TIFF predictor (PREDICTOR)
- TIFF/EP extensions (BATTERY_LEVEL, TIFF_EP_STANDARD_ID, etc.)

**Usage**:
```php
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

$tag = TiffTag::PREDICTOR;             // 0x013D
$tag = TiffTag::ICC_PROFILE;           // 0x8773
$tag = TiffTag::TILE_WIDTH;            // 0x0142
$tag = TiffTag::SUB_IFDS;              // 0x014A
```

### 3. MicrosoftXpTag (Windows XP Proprietary)
**Location**: `src/Model/Microsoft/MicrosoftXpTag.php`  
**Source**: Microsoft Windows XP / Windows Imaging Component  
**Count**: 5 tags

Contains Microsoft Windows XP proprietary metadata tags (UTF-16LE encoded):
- XP_TITLE, XP_COMMENT, XP_AUTHOR, XP_KEYWORDS, XP_SUBJECT

**Usage**:
```php
use MagicSunday\ImageMeta\Model\Microsoft\MicrosoftXpTag;

$tag = MicrosoftXpTag::XP_TITLE;       // 0x9C9B
$tag = MicrosoftXpTag::XP_AUTHOR;      // 0x9C9D
$tag = MicrosoftXpTag::XP_KEYWORDS;    // 0x9C9E
```

### 4. DngTag (Adobe Digital Negative)
**Location**: `src/Model/Dng/DngTag.php`  
**Source**: Adobe DNG Specification v1.0-1.7  
**Count**: ~100 tags

Contains Adobe DNG (Digital Negative) RAW format tags. Already existed, maintained separately.

**Usage**:
```php
use MagicSunday\ImageMeta\Model\Dng\DngTag;

$tag = DngTag::DNG_VERSION;            // 0xC612
$tag = DngTag::COLOR_MATRIX_1;         // 0xC621
$tag = DngTag::CALIBRATION_ILLUMINANT_1; // 0xC65A
```

### 5. LegacyTag (Backwards Compatibility)
**Location**: `src/Model/Legacy/LegacyTag.php`  
**Source**: EXIF 2.x, Microsoft pre-EXIF 3.0 extensions  
**Count**: 13 tags

Contains deprecated or renamed tags from older EXIF versions:
- Legacy aliases (ISO_SPEED_RATINGS_LEGACY → PHOTOGRAPHIC_SENSITIVITY)
- Microsoft pre-EXIF 3.0 extensions (PHOTOGRAPHER_LEGACY, etc.)
- Tags with hex value conflicts (CAMERA_FIRMWARE_VERSION_LEGACY)

**Usage**:
```php
use MagicSunday\ImageMeta\Model\Legacy\LegacyTag;

$tag = LegacyTag::ISO_SPEED_RATINGS_LEGACY;  // 0x8827 (same as PHOTOGRAPHIC_SENSITIVITY)
$tag = LegacyTag::PHOTOGRAPHER_LEGACY;       // 0xE92D
```

## Backwards Compatibility

**Important**: `ExifTag.php` still contains ALL tag constants for backwards compatibility. Existing code continues to work without modification.

The new classes provide:
- ✅ Better code organization
- ✅ Clear source documentation
- ✅ Easier maintenance
- ✅ Type-safe imports for new code

### Migration Example

**Old code (still works)**:
```php
use MagicSunday\ImageMeta\Model\Exif\ExifTag;

$tag = ExifTag::XP_TITLE;              // Still works!
$tag = ExifTag::PREDICTOR;             // Still works!
```

**New code (recommended)**:
```php
use MagicSunday\ImageMeta\Model\Microsoft\MicrosoftXpTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

$tag = MicrosoftXpTag::XP_TITLE;       // Clearer source
$tag = TiffTag::PREDICTOR;             // Clearer source
```

## Accessing Unmapped Tags

Tags without constants in any class can still be accessed via numeric ID:

```php
$metadata = (new MetadataReader())->read('photo.jpg');

// Access ANY tag by numeric ID
$value = $metadata->exifDoc->ifd0->get(0x9999);      // Works!
$value = $metadata->exifDoc->exifIfd->get(0xABCD);   // Works!
$value = $metadata->exifDoc->gpsIfd->get(0x0042);    // Works!
```

**All IFD entries are preserved**, regardless of whether they have a corresponding constant.

## Documentation

For comprehensive analysis of tag sources:
- English: `docs/EXIF_TAG_SOURCES_ANALYSIS.md`
- German: `docs/EXIF_TAG_SOURCES_ANALYSIS_DE.md`
- Summary: `EXIF_TAG_ANALYSIS_SUMMARY.md`

## Tests

Each tag class has corresponding tests:
- `tests/Model/Exif/ExifTagTest.php` - ExifTag validation
- `tests/Model/Exif/ExifTagSourcesTest.php` - EXIF 3.0 spec compliance
- `tests/Model/Tiff/TiffTagTest.php` - TiffTag validation
- `tests/Model/Microsoft/MicrosoftXpTagTest.php` - MicrosoftXpTag validation
- `tests/Model/Dng/DngTagTest.php` - DngTag validation
- `tests/Model/Legacy/LegacyTagTest.php` - LegacyTag validation

## References

- EXIF 3.0: `docs/EXIF-300.pdf`
- TIFF 6.0: `docs/TIFF6.pdf`
- Adobe DNG: `docs/DNG_Spec_1_7_1_0.pdf`
- Microsoft WIC: [Windows Imaging Component Documentation](https://docs.microsoft.com/en-us/windows/win32/wic/-wic-codec-metadataquerylanguage)
