# MagicSunday/ImageMeta

[![CI](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/magicsunday/imagemeta)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-blue)](composer.json)

![EXIF 2.1](https://img.shields.io/badge/EXIF-2.1-blue)
![EXIF 2.2](https://img.shields.io/badge/EXIF-2.2-blue)
![EXIF 2.21](https://img.shields.io/badge/EXIF-2.21-blue)
![EXIF 2.3](https://img.shields.io/badge/EXIF-2.3-blue)
![EXIF 2.31](https://img.shields.io/badge/EXIF-2.31-blue)
![EXIF 2.32](https://img.shields.io/badge/EXIF-2.32-blue)
![EXIF 3.0](https://img.shields.io/badge/EXIF-3.0-blue)

MagicSunday/ImageMeta provides a streaming metadata parser for JPEG, HEIC and ISO Base Media File Format containers. It unifies EXIF, XMP and QuickTime sources into a common PHP domain model.

## Requirements

* PHP 8.4 or newer
* PHP extensions: `ctype`, `date`, `fileinfo`, `hash`, `iconv`, `json`, `pcre`, `xmlreader`
* No external binaries or native extensions are required; the library operates on PHP streams only

## Installation

Install the library via Composer:

```bash
composer require magicsunday/imagemeta
```

## Features at a glance

* Streaming-first container detection with support for JPEG as well as ISO BMFF derivatives such as HEIC, AVIF, MP4 and MOV, handled through `MetadataReader` and the `FormatDetector` component.
* Unified EXIF 3.0, XMP and QuickTime metadata mapped into immutable value objects that expose typed properties instead of raw tag identifiers.
* Maker note decoding with automatic Apple metadata merging plus MPF (Multi-Picture Format) documents, ICC profiles, FlashPix extension streams and EXIF audio tracks surfaced on the aggregate model.
* Optional SHA-1 and MD5 digest calculation alongside MIME type, extension and frame dimension helpers for downstream correlation.
* Lazy assembly of curated metadata via `Metadata::structured()` backed by dedicated accessor aggregates for file, device, lens, exposure, GPS, derived optical values and more.

## Usage

### Reading structured metadata

The structured API wraps immutable value objects and keeps container specific details hidden from consumers:

```php
<?php
use MagicSunday\ImageMeta\MetadataReader;

$meta = (new MetadataReader())->read('photo.heic')->structured();

$meta->file->mimeType;
$meta->device->software;
$meta->lens->lensModel;
$meta->lens->focalLengthMm;
$meta->derived->equivalent35mm;
$meta->exposure->program;
$meta->gps->latitudeCoordinate?->signed;
$meta->preview->hasThumbnail;
$meta->interop->relatedImageWidth;
$meta->standards->exifVersion;
```

### Accessing raw building blocks

When working on specialist workflows you can inspect the extracted blobs and parsed representations directly:

```php
<?php
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;

$metadata = (new MetadataReader())->read('clip.mov', withDigests: true);

$metadata->mimeType;                          // Detected MIME type
$metadata->digestSha1;                         // Optional SHA-1 digest
$metadata->exifDoc?->makerNotes();             // Vendor maker notes
$metadata->quickTime?->stringValue(QuickTimeMeta::MAJOR_BRAND_KEY);
$metadata->mpfDocument?->entries;              // MP Index entries
$metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
$metadata->structured()->derived->fieldOfViewDiagonalDeg;
```

## Structured Metadata API

The structured aggregate described above exposes typed value objects while keeping container-specific tag handling encapsulated.

### Mapping overview

| Field                           | Primary source                                 | Fallback                                 | Converter                                |
|---------------------------------|------------------------------------------------|------------------------------------------|------------------------------------------|
| `camera.make`                   | EXIF `Make`                                    | XMP `tiff:Make`                          | –                                        |
| `lens.model`                    | EXIF `LensModel`                               | –                                        | –                                        |
| `exposure.flash`                | EXIF `Flash`                                   | XMP `exif:Flash`                         | `ValueConverters::flashFromShort()`      |
| `temporal.original`             | EXIF `DateTimeOriginal` + `OffsetTimeOriginal` | XMP `exif:DateTimeOriginal`              | `ValueConverters::parseOffset()`         |
| `gps.speedMs`                   | EXIF `GPSSpeed` + `GPSSpeedRef`                | XMP `exif:GPSSpeed` / `exif:GPSSpeedRef` | `ValueConverters::gpsSpeedToMs()`        |
| `gps.destinationDistanceMetres` | EXIF `GPSDestDistance` + `GPSDestDistanceRef`  | XMP `exif:GPSDestDistance`               | `ValueConverters::gpsDistanceToMetres()` |
| `multiPicture.entries`          | MP Index IFD entries                           | –                                        | `Curate\Exif\ValueFactory`               |

### Temporal fractional seconds harmonisation

ImageMeta mirrors fractional seconds from `SubSecTimeOriginal` or `SubSecTimeDigitized` into the generic EXIF `SubSecTime` slot when the latter is missing. This display-only harmonisation keeps `temporal.subSecTime`, `temporal.subSecTimeOriginal` and `temporal.subSecTimeDigitized` aligned without altering the underlying capture timestamps.

### GPS metadata coverage

ImageMeta normalises every entry from the EXIF 2.32 table 66 GPS IFD. The decoded data is exposed through `ExifDocument::gps()` and dedicated convenience accessors on `ExifDocument`. The following fields are available to consumers:

* Coordinate references and values: `lat_ref`, `lat`, `lon_ref`, `lon`, `alt_ref`, `alt`.
* Navigation metrics: `speed_ref`, `speed_ms`, `track_ref`, `track`, `img_direction_ref`, `img_direction`, `dest_lat_ref`, `dest_lat`, `dest_lon_ref`, `dest_lon`, `dest_bearing_ref`, `dest_bearing`, `dest_distance_ref`, `dest_distance_m`.
* Metadata, timing and accuracy: `version`, `satellites`, `status`, `measure_mode`, `dop`, `map_datum`, `processing_method`, `area_information`, `date`, `time`, `timestamp`, `differential`, `h_positioning_error`.

All fields are trimmed and converted into PHP primitives (floats, ints, strings or `DateTimeImmutable`) so they can be used directly without consulting tag identifiers.

### EXIF 3.0 → Value objects

| Value object         | Fields                                                                                                                      | Source tag(s)                                                                                | Converter/Enum                                                               |
|----------------------|-----------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| `Interop`            | `index`, `version`                                                                                                          | `InteropIndex`, `InteropVersion`                                                             | Hex fallback for binary data                                                 |
| `TiffData`           | `compression`, `photometric`, `ycbcrSubSampling`, `primaryChromaticities`                                                   | `Compression`, `PhotometricInterpretation`, `YCbCrSubSampling`, `PrimaryChromaticities`      | `Compression`, `Photometric`, `ValueConverters::toPrimaryChromaticities()`   |
| `CompositeImageInfo` | `type`, `counts`, `exposureTimesTotal`                                                                                      | `CompositeImage`, `SourceImageNumberOfCompositeImage`, `SourceExposureTimesOfCompositeImage` | `CompositeImage`, rational to float                                          |
| `Standards`          | `exifVersion`, `flashpixVersion`                                                                                            | `ExifVersion`, `FlashpixVersion`                                                             | `ValueConverters::toExifVersion()` (FlashPix defaults to `1.00`)             |
| `Lens`               | `lensSpecification`, `maxApertureFNumber`                                                                                   | `LensSpecification`, `MaxApertureValue`                                                      | `ValueConverters::apexToFNumber()`                                           |
| `Exposure`           | `exposureBiasEv`, `gainControl`, `contrast`                                                                                 | `ExposureBiasValue`, `GainControl`, `Contrast`                                               | `GainControl` enum                                                           |
| `Scene`              | `subjectDistanceRange`                                                                                                      | `SubjectDistanceRange`                                                                       | `SubjectDistanceRange` enum                                                  |
| `Device`             | `rawDevelopingSoftware`, `imageEditingSoftware`, `metadataEditingSoftware`                                                  | `RAWDevelopingSoftware`, `ImageEditingSoftware`, `MetadataEditingSoftware`                   | –                                                                            |
| `Gps`                | `latitude`, `longitude`, `altitude`, `speed*`, `track*`, `timestamp`, navigation metadata                                   | GPS IFD (`GPS*`) with XMP `exif:GPS*` fallbacks                                              | `ValueConverters::gpsFromIfd()`, harmonisation in `Curate\Exif\ValueFactory` |
| `MultiPicture`       | `version`, `imageCount`, `entries`, `totalFrames`, `individualImageNumber`, `imageUidList`, `panoramaAngle`, `panoramaAxis` | MP Index IFD, MP Attribute IFD                                                               | `Curate\Exif\ValueFactory::resolveMultiPicture()`                            |

```php
$s = $meta->structured();
$s->tiff->compression;              // Compression::JPEG
$s->lens->lensSpecification;       // [minF, minAperture, maxF, maxAperture]
$s->composite->type;                // CompositeImage::GeneralComposite
$s->standards->exifVersion;          // "3.00"
```

The aggregate always instantiates each value object. Consumers therefore never have to deal with tag identifiers or container-specific key names.

The diagonal field of view exposed via `$s->derived->fieldOfViewDiagonalDeg` corresponds to the value previously documented as `fovDeg`. The new horizontal and vertical helpers provide axis-specific angles so clients can present per-dimension compositions without additional trigonometry. The circle of confusion used for depth-of-field calculations is exposed through `$s->derived->circleOfConfusionMm`.

The expanded temporal aggregate surfaces raw EXIF offset tags alongside a resolved `DateTimeZone` instance. This makes it possible to reconstruct original capture times even when the offset varies between creation, digitisation and modification steps. File level metadata now reports size, extension and cryptographic digests to help consumers correlate assets or detect tampering.

Apple maker note data now includes semantic style parameters, colour temperature and Live Photo flags from both maker notes and QuickTime metadata. GPS coverage has been widened with horizontal positioning error and full destination navigation metrics from EXIF 2.32 table 66.

Bit-mask sources such as `SceneFlags`, `ImageProcessingFlags` and `PhotosAppFeatureFlags` are decoded so their individual bits populate the normalised `apple.flags` array:

* `SceneFlags`: bit 0 → `nightMode`, bit 1 → `longExposure`
* `ImageProcessingFlags`: bit 0 → `hdrEnabled`, bit 1 → `hdrAuto`
* `PhotosAppFeatureFlags`: bit 0 → `personInPhoto`, bit 1 → `petInPhoto`

Explicit boolean keys continue to override the derived values when both representations are present.

## EXIF/TIFF Compliance

This library tracks compliance with official EXIF 3.0, EXIF 2.32, and TIFF 6.0 specifications through automated analysis.

### Compliance Summary

| Metric | Count | Percentage |
|--------|------:|:----------:|
| Total Specification Tags | 163 | 100% |
| ✅ Implemented | 144 | 88.3% |
| ⚠️ Partial | 19 | 11.7% |
| ❌ Missing | 0 | 0.0% |
| ➕ Extra (not in spec) | 62 | - |
| **Overall Coverage** | **144/163** | **88.3%** |

*Last updated: 2025-11-06 06:55:01 UTC*

### Coverage by Category

| Category | Implemented | Partial | Missing | Total | Coverage |
|----------|------------:|--------:|--------:|------:|---------:|
| TIFF 6.0 Baseline | 33 | 7 | 0 | 40 | 82.5% |
| EXIF Tags | 76 | 10 | 0 | 86 | 88.4% |
| GPS Tags | 32 | 0 | 0 | 32 | 100.0% |
| Interoperability | 3 | 2 | 0 | 5 | 60.0% |

**Where to find implementation gaps**:
- **System guide**: [COMPLIANCE.md](docs/COMPLIANCE.md) - How the compliance system works
- **Raw data**: [compliance-report.json](docs/compliance-report.json) - Machine-readable report

The compliance report is automatically generated on every CI run and available as a downloadable artifact.
