# EXIF/TIFF Compliance Report

This document describes the automated compliance analysis system for tracking EXIF 3.0, EXIF 2.32, and TIFF 6.0 tag coverage in the ImageMeta library.

## Overview

The compliance analyzer compares the library's implementation against the official EXIF and TIFF specifications to ensure comprehensive metadata support. It generates machine-readable reports tracking the status of every tag defined in the specifications.

## Components

### 1. Specification Reference (`resources/exif-spec-tags.yaml`)

Contains the official tag definitions from:
- **EXIF 3.0** - Latest EXIF specification
- **EXIF 2.32** - Widely-used EXIF version
- **TIFF 6.0** - Baseline TIFF tags

Each tag includes:
- Tag ID (hexadecimal)
- Tag name
- Data type
- IFD location (IFD0, ExifIFD, GPSIFD, InteropIFD, PreviewIFD)
- Source specification
- Required/optional status
- Deprecation status
- Description

### 2. Compliance Analyzer (`scripts/analyze-exif-compliance.php`)

PHP script that:
1. Loads specification tags from `resources/exif-spec-tags.yaml`
2. Parses implementation from `src/Exif/Model/ExifTag.php` (constant definitions)
3. Scans `src/Exif/Model/ParsedExif.php` for public getter methods (actual implementation)
4. Determines status for each tag
5. Generates compliance reports in JSON and YAML formats

**Note**: The analyzer directly scans the `ParsedExif` class for public getter methods rather than relying on `exif-map.yaml`, as the latter is not automatically generated and may not be up-to-date.

### 3. Compliance Reports

Generated reports (`docs/compliance-report.json` and `docs/compliance-report.yaml`) include:

#### Summary Statistics
- Total specification tags
- Implemented count
- Partial implementation count
- Missing count
- Extra tags (not in spec)
- Coverage percentage

#### Tag Status Categories
- **implemented**: Constant defined in ExifTag.php AND public getter method exists in ParsedExif
- **partial**: Either constant OR getter method exists, but not both
- **missing**: Neither constant nor getter method found
- **extra**: Implemented but not in EXIF 3.0/2.32/TIFF 6.0 specifications

#### Per-Tag Details
For each tag:
- Tag ID and name
- IFD location
- Source specification
- Required/deprecated status
- Implementation status
- Constant defined (yes/no)
- Getter method exists (yes/no)
- Getter method names (from ParsedExif)
- Implementation notes

## Usage

### Running the Analyzer

```bash
# Run with default 90% coverage threshold
php scripts/analyze-exif-compliance.php

# Run with custom threshold (e.g., 85%)
php scripts/analyze-exif-compliance.php 85
```

The script:
- Generates `docs/compliance-report.json`
- Generates `docs/compliance-report.yaml`
- Prints summary to console
- Exits with code 0 if threshold met, 1 otherwise

### Reading the Reports

**JSON Report** (`docs/compliance-report.json`):
```json
{
  "generated": "2025-11-06T06:55:01+00:00",
  "summary": {
    "total_spec_tags": 163,
    "implemented": 144,
    "partial": 19,
    "missing": 0,
    "extra": 62,
    "coverage_percent": 88.34
  },
  "categories": {
    "tiff_tags": { ... },
    "exif_tags": { ... },
    "gps_tags": { ... },
    "interop_tags": { ... }
  },
  "extra_tags": [ ... ]
}
```

**YAML Report** (`docs/compliance-report.yaml`):
More human-readable format with the same structure.

## CI Integration

The compliance analyzer is integrated into the GitHub Actions CI pipeline:

1. **On every push/PR**: Generates compliance report
2. **Uploads as artifact**: Reports available for download
3. **Optional threshold check**: Can fail build if coverage drops below threshold

### CI Configuration

Added to `.github/workflows/ci.yml`:

```yaml
- id: compliance
  name: EXIF/TIFF Compliance Analysis
  if: ${{ always() && steps.install.conclusion == 'success' }}
  run: php scripts/analyze-exif-compliance.php 50

- name: Upload Compliance Report
  uses: actions/upload-artifact@v4
  if: always()
  with:
    name: compliance-report
    path: docs/compliance-report.*
```

## Compliance Status

### Current Status

As of the latest run:
- **Total Spec Tags**: 163
- **Implemented**: 144 (88.34%)
- **Partial**: 19
- **Missing**: 0
- **Extra**: 62

### Key Categories

#### TIFF 6.0 Baseline Tags (82.5%)
Core TIFF tags for image dimensions, compression, color space, resolution, and orientation.

#### EXIF 2.32/3.0 Tags (88.4%)
Photography metadata including exposure, camera settings, lens information, and timestamps.

#### GPS Tags (100%)
Geolocation data including latitude, longitude, altitude, and direction. All GPS tags are now fully implemented via the `gps()` method in ParsedExif.

#### Interoperability Tags (60%)
Cross-device compatibility information.

#### Preview IFD Tags (EXIF 3.0)
New in EXIF 3.0 for embedded preview images.

### Extra Tags

The library implements 62 additional tags not in the core EXIF 3.0/2.32/TIFF 6.0 specifications. These may include:
- Manufacturer-specific tags (MakerNotes)
- DNG (Digital Negative) tags
- Adobe XMP tags
- Microsoft XP tags
- Legacy tags from older EXIF versions

## Improving Coverage

To improve compliance coverage, see **[TAGS_TO_IMPLEMENT.md](TAGS_TO_IMPLEMENT.md)** for a complete list of the 19 partial tags that need getter methods.

**Quick steps**:

1. **Add missing constants** to `src/Exif/Model/ExifTag.php` (if needed)
2. **Implement getter methods** in `src/Exif/Model/ParsedExif.php`
3. **Add tests** for new tag support
4. **Re-run analyzer** to verify improvement: `composer ci:test:php:compliance`

**Note**: The analyzer scans ParsedExif directly for public getter methods, not `exif-map.yaml`. Focus on implementing actual functionality in ParsedExif.

Many "partial" tags may already have getter methods with different names - check TAGS_TO_IMPLEMENT.md for suggestions on which existing methods might just need aliases.

## Future Enhancements

Planned improvements to the compliance system:

1. **Test coverage tracking**: Link each tag to its test coverage
2. **Cardinality validation**: Verify array counts match spec
3. **Type validation**: Check that VO types match spec types
4. **Unit tracking**: Include measurement units for applicable tags
5. **Version support matrix**: Track which EXIF versions support each tag
6. **HTML report generation**: Create visual compliance dashboard
7. **Automated spec updates**: Parse PDFs to extract tag tables

## References

- EXIF 3.0 Specification: `docs/EXIF-300.pdf`
- EXIF 2.32 Specification: `docs/EXIF-232.pdf`
- TIFF 6.0 Specification: `docs/TIFF6.pdf`
- Official tag reference: `resources/exif-spec-tags.yaml`
- Current compliance report: `docs/compliance-report.json`

## Contributing

When adding support for new EXIF/TIFF tags:

1. Verify tag is in `resources/exif-spec-tags.yaml` (add if missing)
2. Add constant to `src/Exif/Model/ExifTag.php`
3. Implement public getter method in `src/Exif/Model/ParsedExif.php`
4. Add unit tests
5. Run compliance analyzer
6. Update this documentation if needed

## Changelog

### 2025-11-06 (Update 2)
- **Changed analyzer to scan ParsedExif.php directly** instead of relying on exif-map.yaml
- Coverage improved from 64.42% to 88.34%
- GPS tags now show 100% coverage (via `gps()` method)

### 2025-11-06 (Initial)
- Initial compliance analyzer implementation
- Added EXIF 3.0/2.32 and TIFF 6.0 tag specifications
- Generated baseline compliance report
- Integrated into CI pipeline
