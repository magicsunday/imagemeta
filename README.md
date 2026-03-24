<h1 align="center">ImageMeta: Metadata Parser for PHP</h1>

<p align="center">
  Read-only metadata extraction for JPEG, ISO BMFF, TIFF-based, and RIFF/AVI files in PHP.
</p>

<!-- Row 1: CI / Quality badges -->
<p align="center">
  <a href="https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml"><img src="https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

<!-- Row 2: Standards / Tooling badges -->
<p align="center">
  <a href="https://phpstan.org/"><img src="https://img.shields.io/badge/PHPStan-max%20level-brightgreen.svg" alt="PHPStan Max Level"></a>
  <a href="https://phpunit.de/"><img src="https://img.shields.io/badge/PHPUnit-12-blue.svg" alt="PHPUnit 12"></a>
  <a href="https://getrector.com/"><img src="https://img.shields.io/badge/Rector-2.0-orange.svg" alt="Rector 2.0"></a>
  <a href="https://www.php-fig.org/psr/psr-12/"><img src="https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg" alt="PSR-12"></a>
</p>

<!-- Row 3: Compatibility badges -->
<p align="center">
  <a href="composer.json"><img src="https://img.shields.io/badge/php-8.4|8.5-blue" alt="PHP Version"></a>
  <img src="https://img.shields.io/badge/EXIF-2.1-blue" alt="EXIF 2.1">
  <img src="https://img.shields.io/badge/EXIF-2.2-blue" alt="EXIF 2.2">
  <img src="https://img.shields.io/badge/EXIF-2.21-blue" alt="EXIF 2.21">
  <img src="https://img.shields.io/badge/EXIF-2.3-blue" alt="EXIF 2.3">
  <img src="https://img.shields.io/badge/EXIF-2.31-blue" alt="EXIF 2.31">
  <img src="https://img.shields.io/badge/EXIF-2.32-blue" alt="EXIF 2.32">
  <img src="https://img.shields.io/badge/EXIF-3.0-blue" alt="EXIF 3.0">
  <img src="https://img.shields.io/badge/EXIF-3.1-blue" alt="EXIF 3.1">
</p>

<!-- Row 4: Project badges -->
<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/magicsunday/imagemeta" alt="License"></a>
</p>

---

## 📌 Overview
ImageMeta is a PHP library for read-only metadata extraction from image and media containers. It parses metadata from JPEG, ISO BMFF (for example HEIC, AVIF, MOV, MP4), TIFF-based files, JPEG XL containers, and RIFF/AVI video files. The library exposes both low-level parsed documents and a typed structured aggregate for application-level usage. Parsing is defensive by design, with explicit bounds checks and strict validation.

| Key      | Value                                                   |
|----------|---------------------------------------------------------|
| Package  | `magicsunday/imagemeta`                                 |
| PHP      | `>=8.4.0 <8.6.0`                                        |
| Main API | `MagicSunday\ImageMeta\MetadataReader`                  |
| Output   | Raw `Model\Metadata` + typed `Value\StructuredMetadata` |

## ❓ What is this?
ImageMeta reads metadata from supported containers and returns both raw parsed documents and a typed, structured aggregate (`StructuredMetadata`). The structured output normalizes camera, exposure, GPS, temporal, and lens data across EXIF, XMP, IPTC, QuickTime, and RIFF INFO sources via automatic fallback chains — applications get complete metadata without knowing which source holds it. Vendor maker notes (Apple, Samsung, DJI) and ICC color profiles are decoded where present.

## 🎯 Why does this exist?
PHP's built-in `exif_read_data()` is limited to JPEG/TIFF EXIF tags and returns untyped arrays. It cannot read ISO BMFF containers (HEIC, AVIF, MOV, MP4), has no XMP/IPTC/QuickTime support, and offers no structured output model. ImageMeta closes that gap: one API, five container families, six metadata sources, typed value objects with automatic cross-source fallback.

## 🧭 Scope & Non-Goals

**In scope:**

- Local file parsing via signature-based container detection (JPEG, ISO BMFF, TIFF, JXL, RIFF/AVI).
- Extraction and merge of metadata sources: EXIF, XMP, IPTC, QuickTime, RIFF INFO, and ICC profiles.
- Typed structured output (`StructuredMetadata`) with automatic EXIF → XMP → QuickTime → RIFF fallback chains.
- Vendor maker-note decoding (Apple, Samsung, DJI).
- MPF (Multi-Picture Format) document parsing.
- Defensive streaming parser with explicit bounds checks and configurable limits.

**Out of scope:**

- Writing, editing, or re-serializing metadata.
- Pixel/media decoding, rendering, or transcoding.
- Network-based metadata resolution.
- Guaranteed support for every proprietary maker-note dialect.

## 🧩 Supported formats / features

### Containers

| Container | File types | Metadata extracted |
|-----------|------------|-------------------|
| JPEG | `.jpg`, `.jpeg` | EXIF, XMP, IPTC, ICC, MPF, FlashPix, JFIF, EXIF audio |
| ISO BMFF | `.heic`, `.heif`, `.avif`, `.mp4`, `.mov`, `.m4v`, `.3gp` | EXIF, XMP, QuickTime metadata, ICC, item references |
| TIFF | `.tiff`, `.tif`, `.dng`, `.nef`, `.arw`, `.cr2` | EXIF, XMP (tag 700), IPTC (tag 33723), ICC (tag 34675) |
| JXL | `.jxl` | EXIF (`Exif` box), XMP (`xml ` box), HDR gain map (`hrgm`) |
| RIFF/AVI | `.avi` | INFO chunks, XMP (`_PMX`), RIFF EXIF (`LIST 'exif'`), TIFF blobs (`strd`), AVI header |

Detection is signature-based (magic bytes), not extension-based.

### Metadata sources

| Source | Scope |
|--------|-------|
| EXIF | Versions `1.0` through `3.1` with full capability mapping per version |
| XMP | RDF/XML parsing via `XMLReader` (non-network, entity-safe) |
| IPTC | IIM datasets from JPEG APP13 and TIFF tag 33723 |
| QuickTime | Keys/data atoms, mvhd/tkhd/mdhd timestamps, track media metadata |
| RIFF INFO | Key-value pairs (INAM, IART, ICRD, ISFT, IDIT, etc.) |
| ICC | Profile header, tag table, and tag data parsing |

### Vendor maker notes

| Vendor | Coverage |
|--------|----------|
| Apple | HDR, LivePhoto, AutoFocus, AutoExposure, capture identity, scene analysis |
| Samsung | Maker note tag decoding |
| DJI | Maker note tags + mdat protobuf telemetry scanning (GPS, model for truncated recordings) |

### Additional features

| Feature | Details |
|---------|---------|
| MPF | CIPA DC-007-2025, 3rd Edition (all MP type codes including Gain Map) |
| FlashPix | OLE compound document / property set parsing |
| DNG | DNG 1.7.1 tag semantics |
| Structured output | `->structured()` returns typed value objects: Camera, Exposure, GPS, Temporal, Lens, Sensor, Image, Device, Scene, Motion, Regions, MultiPicture |

### Notes

- File-level support depends on whether the input actually contains parseable metadata blocks.
- For JXL, the current scope is metadata-box extraction (EXIF/XMP/gain map); no pixel/codestream decode.
- For RIFF/AVI, vendor-specific JUNK-chunk maker notes are not yet supported.

## 🚀 Usage

```bash
composer require magicsunday/imagemeta
```

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

$metadata   = MetadataReader::createDefault()->read('/path/to/photo.heic');
$structured = $metadata->structured();

// Typed structured access (automatic EXIF → XMP → QuickTime → RIFF fallback):
$cameraMake = $structured->hardware->camera->make;
$iso        = $structured->settings->exposure->settings->iso;
$latitude   = $structured->locationTime->gps->position->latitudeCoordinate?->signed;
$createDate = $structured->locationTime->temporal->original;

// Low-level raw access:
$exif      = $metadata->exifDoc;
$xmp       = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
$quickTime = $metadata->quickTime;
```

JPEG XL example:

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

$metadata = MetadataReader::createDefault()->read('/path/to/image.jxl');

// JXL currently exposes metadata carried in Exif/xml boxes.
$exif = $metadata->exifDoc;
$xmp = $metadata->xmpDoc;
```

AVI example:

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

$metadata = MetadataReader::createDefault()->read('/path/to/video.avi');

// AVI exposes INFO chunks, RIFF-native EXIF fields, XMP, and embedded TIFF/EXIF blobs.
$info      = $metadata->riffInfo;
$title     = $info?->get('INAM');
$software  = $info?->get('ISFT');
$aviHeader = $metadata->riffAviHeader;
$width     = $aviHeader?->width;
$height    = $aviHeader?->height;
```

## 🛡️ Error handling & guarantees

- **Exceptions:**
  - `MagicSunday\ImageMeta\Core\ParseError` for malformed/unsupported content and validation failures.
  - `MagicSunday\ImageMeta\Core\BoundsError` for out-of-range offset/length access.
- **Guarantees:**
  - Bounds and limits are enforced in stream and parser layers (for example `src/Core/*`, `src/Parse/ParserLimits.php`).
  - XMP parsing uses `XMLReader` with `LIBXML_NONET` (and `LIBXML_NO_XXE` when available), disabling network/entity resolution.
  - The library is read-only and does not modify input files.
- **Not guaranteed:**
  - Full coverage of all proprietary maker-note formats.

## 🛠️ Development

Development uses a Docker buildbox. All commands are available via `make`:

```bash
make build      # Build the Docker image (first time)
make install    # Install composer dependencies
make test       # Run full CI pipeline (mandatory gate before any commit)
```

`make test` runs (in order): phplint → php-cs-fixer (dry-run) → rector (dry-run) → phpstan → phpunit → jscpd

Additional targets:

| Target | Description |
|--------|-------------|
| `make lint` | PHP linter only |
| `make cgl-check` | Code style check (dry-run) |
| `make cgl` | Fix code style |
| `make rector-check` | Rector check (dry-run) |
| `make rector` | Apply rector rules |
| `make stan` | PHPStan analysis |
| `make unit` | PHPUnit tests only |
| `make coverage` | PHPUnit with HTML + Clover coverage report |
| `make cpd` | Copy-paste detection (jscpd) |
| `make bash` | Open a shell in the buildbox container |
| `make format FILE=<path>` | Format metadata output for a file (`--debug`, `--digest` flags) |

### Prerequisites (without Docker)

- PHP `>=8.4.0 <8.6.0`
- Extensions: `ctype`, `date`, `fileinfo`, `hash`, `iconv`, `json`, `mbstring`, `pcre`, `xmlreader`

```bash
composer install
composer ci:test
```

## 📚 Documentation & Specs

Specifications and reference material live in `docs/`.

Primary files used by parser development:

- `docs/EXIF-310.pdf` -- EXIF 3.1 (CIPA DC-008-2026)
- `docs/EXIF-300.pdf` (plus historical revisions `docs/EXIF-2*.pdf`)
- `docs/TIFF6.pdf`
- `docs/ISO_IEC_14496-12_2015.pdf`
- `docs/Quicktime-File-Format-2012.pdf`
- `docs/XMP.pdf`
- `docs/ICC.pdf`
- `docs/MPF.pdf` -- MPF 3rd Edition (CIPA DC-007-2025)
- `docs/DNG_Spec_1_7_1_0.pdf`

HTML mirrors (`docs/*.html`) are available for faster navigation/search during implementation.

## 🤝 Contributing

See `CONTRIBUTING.md` for contributor workflow and minimal setup.

If contributions are prepared or modified by an LLM/agent, follow `AGENTS.md` (and `tests/AGENTS.md` for test-only scope).
