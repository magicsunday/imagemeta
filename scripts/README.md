# Scripts

This directory contains utility scripts for the ImageMeta library.

## Available Scripts

### exiftool-format.php

Generates output similar to `exiftool -H -a -u -g1` for comparing metadata extraction results.

**Purpose:**  
This script formats the metadata extracted by the ImageMeta library in a way that mimics the output of the popular `exiftool` command-line utility. This makes it easy to compare the results between the two tools and verify that the library is extracting metadata correctly.

**Usage:**
```bash
php scripts/exiftool-format.php <image-file>
```

**Examples:**
```bash
# Display metadata for a JPEG file
php scripts/exiftool-format.php photo.jpg

# Display metadata for a HEIC file
php scripts/exiftool-format.php /path/to/image.heic

# Compare with actual exiftool output
exiftool -H -a -u -g1 photo.jpg > exiftool.txt
php scripts/exiftool-format.php photo.jpg > imagemeta.txt
diff -u exiftool.txt imagemeta.txt
```

**Output Format:**

The script organizes metadata into sections similar to exiftool:

- **ExifTool** - Version information
- **System** - File system metadata (name, size, permissions, dates)
- **File** - Container information (file type, MIME type, dimensions)
- **IFD0** - Main EXIF tags from IFD0 (with hex tag IDs)
- **ExifIFD** - EXIF-specific tags (with hex tag IDs)
- **Apple** - Apple MakerNotes data (for Apple devices)
- **GPS** - GPS location data (with hex tag IDs)
- **XMP** - XMP metadata grouped by namespace
- **ICC-header** - ICC color profile information
- **Composite** - Derived/calculated values

**Features:**

- Hexadecimal tag IDs for EXIF tags (e.g., `0x010f Make`)
- Tag names matching exiftool conventions
- Proper formatting of EXIF value types (rational, numeric lists, etc.)
- GPS coordinates in degrees/minutes/seconds format
- Comprehensive composite values (Field of View, Light Value, etc.)
- Support for Apple MakerNotes
- XMP data grouped by namespace
- Binary data detection and indication

### analyze-exif-compliance.php

Analyzes implementation coverage of EXIF 3.0, EXIF 2.32, and TIFF 6.0 tags against the official specifications.

**Purpose:**  
Tracks compliance with official EXIF/TIFF specifications and generates machine-readable compliance reports.

**Usage:**
```bash
php scripts/analyze-exif-compliance.php [threshold]
```

**Examples:**
```bash
# Run with default 90% coverage threshold
php scripts/analyze-exif-compliance.php

# Run with custom 85% coverage threshold
php scripts/analyze-exif-compliance.php 85
```

See the main project README for more details on compliance tracking.

## Requirements

All scripts require:
- PHP 8.4 or newer
- Composer dependencies installed (`composer install`)

## Development

When creating new scripts:

1. Add a shebang line: `#!/usr/bin/env php`
2. Make the script executable: `chmod +x scripts/your-script.php`
3. Follow PSR-12 coding standards
4. Include comprehensive usage documentation in the file header
5. Update this README with a description of the new script
