# Scripts

This directory contains utility scripts for analyzing and working with image metadata.

## reverse-engineer-apple-makernotes.php

**Purpose:** Extract and analyze Apple Maker Notes from images to help reverse engineer unknown fields.

**Documentation:** See [docs/APPLE-MAKERNOTES-REVERSE-ENGINEERING.md](../docs/APPLE-MAKERNOTES-REVERSE-ENGINEERING.md)

**Quick Start:**
```bash
# Analyze a single image
php scripts/reverse-engineer-apple-makernotes.php photo.heic

# Analyze with verbose output
php scripts/reverse-engineer-apple-makernotes.php photo.jpg --verbose

# Compare multiple files
php scripts/reverse-engineer-apple-makernotes.php photos/ --compare

# Export to JSON
php scripts/reverse-engineer-apple-makernotes.php photo.heic --format=json --output=analysis.json
```

## analyze-exif-compliance.php

**Purpose:** Analyze EXIF/TIFF compliance against official specifications.

**Usage:**
```bash
php scripts/analyze-exif-compliance.php [threshold]
```

Generates compliance reports in `docs/compliance-report.json` and `docs/compliance-report.yaml`.
