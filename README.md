<h1 align="center">ImageMeta: Metadata Parser for PHP</h1>

<p align="center">
  Read-only metadata extraction for JPEG, ISO BMFF, and TIFF-based files in PHP.
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
ImageMeta is a PHP library for read-only metadata extraction from image and media containers. It parses metadata from JPEG, ISO BMFF (for example HEIC, AVIF, MOV, MP4), TIFF-based files, and JPEG XL containers. The library exposes both low-level parsed documents and a typed structured aggregate for application-level usage. Parsing is defensive by design, with explicit bounds checks and strict validation.

| Key      | Value                                                   |
|----------|---------------------------------------------------------|
| Package  | `magicsunday/imagemeta`                                 |
| PHP      | `>=8.4.0 <8.6.0`                                        |
| Main API | `MagicSunday\ImageMeta\MetadataReader`                  |
| Output   | Raw `Model\Metadata` + typed `Value\StructuredMetadata` |

## ❓ What is this?
ImageMeta reads metadata from supported containers and returns a unified, typed PHP model. It is designed for integration scenarios that need predictable parsing behavior across EXIF, XMP, IPTC, and QuickTime metadata sources.

## 🎯 Why does this exist?
Many PHP applications need one consistent metadata API across modern container formats and metadata families. Typical standard functions such as `exif_read_data()` are EXIF-focused and do not provide a unified, typed model across JPEG, ISO BMFF, and TIFF-based inputs. This project exists to close that integration gap with deterministic parser behavior.

## 🧭 Scope & Non-Goals

**In scope:**

- Local file parsing via signature-based container detection.
- Extraction and merge of supported metadata sources: EXIF, XMP, IPTC, and QuickTime metadata.
- Exposure of both raw and structured output models.
- Defensive parsing with explicit bounds checks and parser limits.

**Out of scope:**

- Writing, editing, or re-serializing metadata.
- Pixel/media decoding, rendering, or transcoding.
- Network-based metadata resolution.
- JPEG XL codestream/pixel decoding or rendering.
- Guaranteed support for every proprietary maker-note dialect.

## 🧩 Supported formats / features

| Area                               | Status                                                                  |
|------------------------------------|-------------------------------------------------------------------------|
| Containers                         | `JPEG`, `ISO BMFF`, `TIFF`, `JXL`                                       |
| JXL                                | Container parsing: EXIF (`Exif` box) + XMP (`xml ` box) extraction      |
| EXIF versions (capability mapping) | `1.0`, `1.1`, `2.0`, `2.1`, `2.2`, `2.21`, `2.3`, `2.31`, `2.32`, `3.0`, `3.1` |
| XMP                                | RDF/XML parsing via `XMLReader`                                         |
| IPTC                               | IIM extraction from JPEG APP13                                          |
| QuickTime metadata                 | Extracted from ISO BMFF structures                                      |
| JPEG auxiliary payloads            | ICC profile, MPF, FlashPix streams, EXIF audio streams                  |
| Output model                       | `MagicSunday\ImageMeta\Model\Metadata` + `->structured()`               |

Notes:

- Container support is signature-based; there is no static extension whitelist.
- File-level support depends on whether the input actually contains parseable metadata blocks.
- For JXL, the current scope is metadata-box extraction (EXIF/XMP); no pixel/codestream decode and no IPTC/QuickTime extraction path.

## 🚀 Usage

```bash
composer require magicsunday/imagemeta
```

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

$metadata = MetadataReader::createDefault()->read('/path/to/photo.heic');
$structured = $metadata->structured();

$cameraMake = $structured->camera->make;
$iso = $structured->exposure->iso;
$latitude = $structured->gps->latitudeCoordinate?->signed;

// Optional low-level access:
$exif = $metadata->exifDoc;
$xmp = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
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
  - Fully streaming behavior in every path (standalone TIFF is currently materialized before EXIF parsing).

## 🛠️ Development

Prerequisites:

- PHP `>=8.4.0 <8.6.0`
- Extensions: `ctype`, `date`, `fileinfo`, `hash`, `iconv`, `json`, `pcre`, `xmlreader`

Install dependencies:

```bash
composer install
```

Run the mandatory quality gate:

```bash
composer ci:test
```

`ci:test` includes:

- Linting (`phplint`)
- Coding standards dry-run (`php-cs-fixer --dry-run`)
- Refactoring dry-run (`rector --dry-run`)
- Static analysis (`phpstan`)
- Unit tests (`phpunit`)
- Copy/paste detection (`jscpd`)

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
- `docs/DNG_Spec_1_7_1_0.pdf`

HTML mirrors (`docs/*.html`) are available for faster navigation/search during implementation.

## 🤝 Contributing

See `CONTRIBUTING.md` for contributor workflow and minimal setup.

If contributions are prepared or modified by an LLM/agent, follow `AGENTS.md` (and `tests/AGENTS.md` for test-only scope).
