# Apple Maker Notes Reverse Engineering

This document describes the procedure for reverse engineering Apple Maker Notes to understand unknown fields and structures in Apple's proprietary metadata format.

## Overview

Apple devices (iPhones, iPads, Macs) embed rich metadata in images and videos through EXIF Maker Notes. These maker notes use Apple's binary property list (bplist) format and often contain NSKeyedArchive structures. While some fields are documented through reverse engineering efforts, many remain unknown or undocumented.

This guide provides tools and procedures to extract, analyze, and understand these maker note fields.

## Tools

### reverse-engineer-apple-makernotes.php

Main CLI tool for extracting and analyzing Apple maker notes from image files.

**Location:** `scripts/reverse-engineer-apple-makernotes.php`

**Usage:**
```bash
# Analyze single file
php scripts/reverse-engineer-apple-makernotes.php photo.heic

# Analyze with verbose type information
php scripts/reverse-engineer-apple-makernotes.php photo.jpg --verbose

# Analyze directory and compare fields
php scripts/reverse-engineer-apple-makernotes.php photos/ --compare

# Export to JSON
php scripts/reverse-engineer-apple-makernotes.php photo.heic --format=json --output=analysis.json

# Show only unknown fields
php scripts/reverse-engineer-apple-makernotes.php photo.heic --unknown-only

# Show only known/mapped fields
php scripts/reverse-engineer-apple-makernotes.php photo.heic --known-only
```

**Options:**
- `--format=json|yaml|text` - Output format (default: text)
- `--output=<file>` - Write to file instead of stdout
- `--raw` - Include raw binary payload as hex dump
- `--compare` - Compare fields across multiple files
- `--known-only` - Show only known/mapped fields
- `--unknown-only` - Show only unknown/unmapped fields
- `--verbose` - Show detailed type information

## Reverse Engineering Workflow

### 1. Collect Sample Images

Gather a diverse set of images from different:
- Device models (iPhone 12, iPhone 13, iPhone 14, iPhone 15, etc.)
- Camera modes (Photo, Portrait, Night mode, Panorama, etc.)
- Capture conditions (HDR, Live Photo, Burst, etc.)
- iOS versions
- Scenarios (underwater, low light, macro, etc.)

### 2. Extract Maker Notes

Use the reverse engineering tool to extract maker notes from your sample set:

```bash
php scripts/reverse-engineer-apple-makernotes.php samples/ --compare --format=json --output=comparison.json
```

This will:
- Parse all image files in the directory
- Extract Apple maker notes from each
- Compare fields across all files
- Show which fields appear consistently vs. conditionally

### 3. Analyze Field Patterns

#### Common vs. Rare Fields

Fields present in all samples are likely core metadata (e.g., ContentIdentifier, MakerNoteVersion).
Fields present in subset of samples are feature-specific (e.g., SemanticStyle* only with photographic styles enabled).

#### Type Analysis

Use `--verbose` to see data types:
```bash
php scripts/reverse-engineer-apple-makernotes.php photo.heic --verbose
```

Common types:
- `int` - Enumeration codes, flags, indices
- `float` - Measurements, ratios, percentages
- `string` - Identifiers, UUIDs, names
- `array` - Vectors, matrices, lists
- `bool` - Feature flags
- `RunTime` - QuickTime temporal values

#### Value Range Analysis

For numeric fields, collect min/max/common values across samples:
- Integer enumerations usually have small ranges (0-10)
- Floats may represent ratios (0.0-1.0) or physical measurements
- Arrays often have fixed sizes (e.g., 3-component vectors, 3×3 matrices)

### 4. Correlate with Known Metadata

Cross-reference unknown fields with:
- Standard EXIF tags (exposure, ISO, focal length)
- XMP metadata
- QuickTime metadata (for HEIC/MOV)
- File properties (resolution, format)

Look for:
- **Duplicated values** - Unknown field matches known EXIF tag
- **Computed relationships** - Unknown field is derived from known values
- **Complementary data** - Unknown field fills gaps in standard metadata

### 5. Test Hypotheses

When you identify a potential field meaning:

1. **Collect targeted samples** - Vary the suspected parameter
2. **Observe value changes** - Confirm correlation
3. **Test edge cases** - Verify behavior at boundaries
4. **Document findings** - Record assumptions and evidence

Example: Identifying a "NightMode" field
```bash
# Collect samples
# - Regular photos (no night mode)
# - Night mode photos
# - Photos with night mode icon shown but not used

# Analyze
php scripts/reverse-engineer-apple-makernotes.php samples/ --compare --unknown-only

# Look for boolean field that:
# - Is false for regular photos
# - Is true for night mode photos
# - Might be true/false for ambiguous cases
```

### 6. Understand Binary Structures

Some fields contain nested structures:

#### Binary Plists
Raw maker notes are bplist00 format. The tool automatically decodes these.

#### NSKeyedArchive
Many maker notes use NSKeyedArchive serialization. The tool unarchives these into readable dictionaries.

#### Nested Dictionaries
Fields like `RunTime` contain sub-fields (epoch, timescale, value, flags).

#### Arrays and Matrices
- `AccelerationVector`: 3-element float array [x, y, z]
- `ColorCorrectionMatrix`: 9-element float array (3×3 matrix in row-major order)
- `HdrGain`: Per-channel gain values

### 7. Bitfields and Flags

Some integer fields are bitfields where each bit represents a boolean flag:

- `SceneFlags`: bit 0 = night mode, bit 1 = long exposure
- `ImageProcessingFlags`: bit 0 = HDR enabled, bit 1 = HDR auto
- `PhotosAppFeatureFlags`: bit 0 = person detected, bit 1 = pet detected

Identify bitfields by:
- Values are powers of 2 or sums of powers of 2
- Values correlate with multiple binary conditions
- Field name suggests flags/options

### 8. String Enumerations

Some string fields are enumerations:

```
CameraType:
  - "Wide"
  - "Tele"
  - "UltraWide"
  - "TrueDepth"

HDRImageType:
  - "HDR"
  - "SDR"
  
ImageCaptureType:
  - "Standard"
  - "Burst"
  - "TimeLapse"
```

Integer codes may map to these strings (e.g., CameraType: 0="Wide", 1="Tele").

## Known Fields Reference

### Core Identifiers
- `ContentIdentifier` - Unique content UUID
- `ImageUniqueID` - Image-specific identifier
- `PhotoIdentifier` - Photos app identifier
- `ImageCaptureRequestID` - Capture request identifier
- `BurstUUID` - Burst sequence identifier

### Camera Hardware
- `CameraType` - Physical camera (Wide/Tele/UltraWide)
- `MakerNoteVersion` - Maker note format version

### HDR and Dynamic Range
- `HdrHeadroom`/`HDRHeadroom` - HDR headroom value
- `HdrGain`/`HDRGain` - Per-channel HDR gain
- `HDRImageType`/`HdrImageType` - HDR classification

### Auto Exposure (AE)
- `AEStable` - Exposure stability flag
- `AETarget` - Target luminance
- `AEAverage` - Average luminance

### Auto Focus (AF)
- `AFStable` - Focus stability flag
- `AFPerformance` - Focus performance metric
- `AFMeasuredDepth` - Measured depth in meters
- `AFConfidence` - Focus confidence (0.0-1.0)
- `FocusPosition` - Lens focus position
- `FocusDistanceRange` - [near, far] bounds in meters

### Image Quality
- `SNRSetting`/`SNR` - Signal-to-noise ratio
- `SignalToNoiseRatioType` - SNR measurement type
- `LuminanceNoiseAmplitude` - Noise amplitude
- `QualityHint` - Processing quality hint

### Photographic Styles (Semantic Styles)
- `SemanticStylePreset` - Style preset name
- `SemanticStyleWarmth` - Warmth adjustment
- `SemanticStyleTone` - Tone adjustment

### Color
- `ColorTemperature` - White balance in Kelvin
- `ColorCorrectionMatrix` - 3×3 color matrix

### Motion and Orientation
- `AccelerationVector` - [x, y, z] acceleration
- `OISMode` - Optical image stabilization mode

### Live Photos
- `RunTime` - CMTime structure (epoch, timescale, value, flags)
- `livePhotoIndex` - Representative frame index
- `livePhotoTime` - Normalized timestamp in seconds

### Feature Flags
- `HDR` - HDR enabled
- `LivePhoto` - Live Photo enabled
- `NightMode` - Night mode used
- `LongExposure` - Long exposure mode
- `PersonInPhoto` - Person detected
- `PetInPhoto` - Pet detected

Flags may be standalone boolean fields or decoded from bitfield integers like:
- `SceneFlags`
- `ImageProcessingFlags`
- `PhotosAppFeatureFlags`

## Mapping New Fields

When you've identified a new field:

1. **Document the field**
   - Add to `AppleMaps.php` if it's an enumeration or lookup table
   - Add to `AppleMakerNotes.php` property list
   - Add extraction logic to `AppleDecoder.php`

2. **Add tests**
   - Create test with known value
   - Test parsing and type conversion
   - Test edge cases (null, min/max, invalid)

3. **Update documentation**
   - Add to this document's "Known Fields" section
   - Add to README if user-facing
   - Update inline code comments with field semantics

4. **Consider fallbacks**
   - Check if field exists in XMP or standard EXIF
   - Implement fallback if appropriate
   - Document precedence order

## Common Patterns

### Rational Values
Fields representing fractions may appear as:
- String: `"3/2"` or `"3 2"`
- Array: `[3, 2]` or `{"numerator": 3, "denominator": 2}`
- Float: `1.5` (already computed)

The decoder handles all formats in `normaliseRationalFloat()`.

### Nested Values
Fields with wrapper objects:
- `{"value": 42}` or `{"Value": 42}`
- `{"values": [1, 2, 3]}` or `{"Values": [1, 2, 3]}`

The decoder recursively unwraps these in extraction methods.

### Case Variations
Apple uses inconsistent casing:
- `HDRHeadroom` vs. `HdrHeadroom`
- `SNRSetting` vs. `SNR`
- Keys with/without prefixes

The decoder checks multiple key variations in `*Value()` methods.

## Advanced Topics

### Binary Plist Format
Apple's binary property list format is efficiently decoded by `BinaryPlistDecoder`. Key features:
- Object references and offsets
- Multiple data types (int, float, string, date, data, array, dict, bool, null)
- Compact encoding for repeated values

### NSKeyedArchive
Objective-C object serialization format. The `KeyedArchiveUnarchiver` resolves:
- Object graphs with `CF$UID` references
- Class types and hierarchies
- Recursive object structures

### QuickTime Integration
HEIC and MOV files embed maker notes in different locations:
- JPEG APP1 segment (traditional)
- QuickTime 'meta' atom
- ISO BMFF 'uuid' box

The `MetadataReader` handles all containers transparently.

## Debugging Tips

### Enable Verbose Mode
```bash
php scripts/reverse-engineer-apple-makernotes.php photo.heic --verbose
```

### Check Raw Payload
```bash
php scripts/reverse-engineer-apple-makernotes.php photo.heic --raw
```

### Isolate Unknown Fields
```bash
php scripts/reverse-engineer-apple-makernotes.php photo.heic --unknown-only
```

### Compare Device Generations
Collect samples from:
- iPhone 11 (A13, no LiDAR)
- iPhone 12 Pro (A14, LiDAR)
- iPhone 13 Pro (A15, Cinematic Mode)
- iPhone 14 Pro (A16, Photonic Engine)
- iPhone 15 Pro (A17 Pro, USB 3)

New fields often correlate with hardware capabilities.

### Cross-Reference Sources
- [ExifTool](https://exiftool.org/) - Comprehensive metadata tool
- [Apple's ImageIO documentation](https://developer.apple.com/documentation/imageio)
- Community reverse engineering efforts (forums, GitHub issues)

## Best Practices

1. **Never guess** - Only document fields with strong evidence
2. **Test thoroughly** - Verify across multiple samples and conditions
3. **Document uncertainty** - Note assumptions and confidence level
4. **Respect privacy** - Don't share personal photos publicly
5. **Version awareness** - Fields may change meaning across iOS versions
6. **Preserve raw data** - Keep original files for re-analysis
7. **Share findings** - Contribute back to the community

## Related Files

- `src/MakerNotes/AppleDecoder.php` - Main Apple maker notes parser
- `src/MakerNotes/Apple/AppleMakerNotes.php` - Value object with known fields
- `src/MakerNotes/Apple/AppleMaps.php` - Enumeration lookups and constants
- `src/MakerNotes/Apple/BinaryPlistDecoder.php` - Binary plist parser
- `src/MakerNotes/Apple/KeyedArchiveUnarchiver.php` - NSKeyedArchive decoder
- `scripts/reverse-engineer-apple-makernotes.php` - Reverse engineering tool

## Contributing

When submitting newly discovered fields:

1. Open an issue with field description and evidence
2. Include sample files (if not privacy-sensitive)
3. Provide device/iOS version information
4. Submit PR with implementation and tests
5. Update this documentation

## License

This reverse engineering work is for interoperability and research purposes under fair use principles. Apple's maker note format is proprietary but extracting metadata from files you own is legal in most jurisdictions.

---

**Last Updated:** 2025-11-08
**Maintainer:** MagicSunday
