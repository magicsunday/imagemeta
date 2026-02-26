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
</p>

<!-- Row 4: Project badges -->
<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/github/license/magicsunday/imagemeta" alt="License"></a>
</p>

---

## 📌 Overview
ImageMeta is a PHP library for read-only metadata extraction from image and media containers. It parses metadata from JPEG, ISO BMFF (for example HEIC, AVIF, MOV, MP4), and TIFF-based files. The library exposes both low-level parsed documents and a typed structured aggregate for application-level usage. Parsing is defensive by design, with explicit bounds checks and strict validation.

| Key      | Value                                                   |
|----------|---------------------------------------------------------|
| Package  | `magicsunday/imagemeta`                                 |
| PHP      | `>=8.4.0 <8.6.0`                                        |
| Main API | `MagicSunday\ImageMeta\MetadataReader`                  |
| Output   | Raw `Model\Metadata` + typed `Value\StructuredMetadata` |

## ✅ What it does
ImageMeta reads metadata from supported containers and returns one typed PHP model you can use in application code.

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
- JPEG XL metadata parsing (JXL is detected, then rejected as unsupported for parsing).
- Guaranteed support for every proprietary maker-note dialect.

## 🧩 Supported formats / features

| Area                               | Status                                                                  |
|------------------------------------|-------------------------------------------------------------------------|
| Containers                         | `JPEG`, `ISO BMFF`, `TIFF`                                              |
| JXL                                | Detection only; parsing not implemented                                 |
| EXIF versions (capability mapping) | `1.0`, `1.1`, `2.0`, `2.1`, `2.2`, `2.21`, `2.3`, `2.31`, `2.32`, `3.0` |
| XMP                                | RDF/XML parsing via `XMLReader`                                         |
| IPTC                               | IIM extraction from JPEG APP13                                          |
| QuickTime metadata                 | Extracted from ISO BMFF structures                                      |
| JPEG auxiliary payloads            | ICC profile, MPF, FlashPix streams, EXIF audio streams                  |
| Output model                       | `MagicSunday\ImageMeta\Model\Metadata` + `->structured()`               |

Notes:

- Container support is signature-based; there is no static extension whitelist.
- File-level support depends on whether the input actually contains parseable metadata blocks.

## 🚀 Quick Start

```bash
composer require magicsunday/imagemeta
```

```php
<?php

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

$metadata = MetadataReader::createDefault()->read('/path/to/photo.heic');
$structured = $metadata->structured();

$cameraMake = $structured->hardware->camera->make;
$iso = $structured->settings->exposure->settings?->iso;
$latitude = $structured->locationTime->gps->position?->latitudeCoordinate?->signed;

// Optional low-level access:
$exif = $metadata->exifDoc;
$xmp = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
$quickTime = $metadata->quickTime;
```

Use this when your application needs one metadata read path for JPEG, ISO BMFF, and TIFF-based files.

## 🧪 How to use it

### Common scenario: normalized metadata for business logic

```php
$metadata = \MagicSunday\ImageMeta\MetadataReader::createDefault()->read('/path/to/file.jpg');
$structured = $metadata->structured();

$capturedAt = $structured->locationTime->capture->dateTime;
$lensModel = $structured->hardware->lens->lensModel;
$gps = $structured->locationTime->gps->position?->latitudeCoordinate?->signed;
```

Use `structured()` when you need stable property names across different container formats.

### Optional scenario: include content digests

```php
$metadata = \MagicSunday\ImageMeta\MetadataReader::createDefault()->read('/path/to/file.heic', true);
$sha1 = $metadata->digestSha1;
$md5 = $metadata->digestMd5;
```

Set the second `read()` argument to `true` when you need reproducible file identity values for deduplication or audit workflows.

### Optional scenario: access low-level parser output

```php
$metadata = \MagicSunday\ImageMeta\MetadataReader::createDefault()->read('/path/to/file.mov');

$exifBlobCount = count($metadata->exifBlobs);
$xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
$quickTime = $metadata->quickTime;
```

Use low-level fields when your integration needs source-level payload inspection in addition to normalized structured values.

## 🧾 Configuration / API reference

### `MetadataReader::createDefault(?TiffExifParserInterface $tiffReader = null): MetadataReader`

- **Description:** Creates a `MetadataReader` with bundled parser components.
- **Parameters:**
  - `$tiffReader` (optional): provide a custom TIFF/EXIF parser implementation for advanced integration and testing.
- **Returns:** `MetadataReader`.
- **Error behavior:** this factory method does not perform file I/O and does not throw parser errors on its own.
- **Example:**

```php
$reader = \MagicSunday\ImageMeta\MetadataReader::createDefault();
```

### `MetadataReader::read(string $path, bool $withDigests = false): Metadata`

- **Description:** Detects the container type from file content and extracts available metadata into one `Metadata` aggregate.
- **Parameters:**
  - `$path`: absolute or relative file path to a local file. Directories and unsupported or malformed files are rejected.
  - `$withDigests`: when `true`, calculates SHA-1 and MD5 and adds them to the returned metadata.
- **Returns:** `MagicSunday\ImageMeta\Model\Metadata`.
- **Error behavior:**
  - Throws `MagicSunday\ImageMeta\Core\ParseError` for malformed/unsupported content, non-file paths, and parser validation failures.
  - Throws `MagicSunday\ImageMeta\Core\BoundsError` for out-of-range stream access.
- **Example:**

```php
$metadata = \MagicSunday\ImageMeta\MetadataReader::createDefault()->read('/path/to/file.avif', true);
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

## 🛠️ Troubleshooting

### `ParseError`: "Path is a directory, not a file"

`read()` only accepts file paths. Pass a concrete file path, not a directory path.

### `ParseError` on empty, unsupported, or corrupted files

The reader uses signature-based detection and strict parser validation. Verify that the file contains a supported container (`JPEG`, `ISO BMFF`, `TIFF`) and valid metadata blocks.

### Missing fields in `structured()`

Structured values are nullable when a source payload does not provide the corresponding metadata. Check low-level fields (`exifDoc`, `xmpDoc`, `quickTime`, blobs) if you need to diagnose source availability.

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
