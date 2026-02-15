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

---

### create-architecture-issues.sh

Creates GitHub issues from architecture violation tickets with appropriate labels.

**Purpose:**  
This script automates the creation of GitHub issues from the architecture analysis documented in `ARCHITECTURE_VIOLATIONS_TICKETS.md`. It parses all 14 tickets and creates corresponding issues with proper labels for priority levels and violated principles.

**Prerequisites:**
- GitHub CLI (`gh`) must be installed and authenticated
- Write access to the repository

**Usage:**
```bash
# Preview what would be created (recommended first)
./scripts/create-architecture-issues.sh --dry-run

# Create actual issues and labels
./scripts/create-architecture-issues.sh
```

**What it does:**
1. **Validates prerequisites** - checks for `gh` CLI and authentication
2. **Creates/updates labels** - sets up priority, SOLID, and principle labels
3. **Parses tickets** - extracts all 14 tickets from the markdown document
4. **Creates issues** - generates GitHub issues with appropriate titles, descriptions, and labels

**Labels Created:**

- **Priority**: `priority-high`, `priority-medium`, `priority-low`
- **SOLID Principles**: `solid-srp`, `solid-isp`, `solid-dip`, `solid-ocp`, `solid-lsp`
- **Other Principles**: `dry`, `kiss`, `yagni`, `grasp`, `lod`, `soc`, `coc`
- **Types**: `architecture`, `refactoring`, `technical-debt`

**Tickets Created:** 14 issues total
- 3 High Priority (god classes, fat interfaces)
- 5 Medium Priority (DRY violations, over-engineering, coupling)
- 6 Low Priority (method length, enums, documentation)

**Options:**
- `--dry-run` - Preview changes without creating issues (recommended for testing)

**Example Output:**
```
========================================
Architecture Issues Creator
========================================

DRY RUN MODE - No changes will be made

✓ Prerequisites OK
✓ Labels setup complete

Processing Ticket #1 (line 38)...
[DRY-RUN] Would create issue #1:
  Title: God Class - TiffExifParser violates SRP with 10,294 LOC and 200 methods
  Labels: architecture,refactoring,technical-debt,priority-high,solid-srp

...

✓ Processed 14 tickets
✓ Complete!
========================================
```

---

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
