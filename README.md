# MagicSunday/ImageMeta

[![CI](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/imagemeta/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/magicsunday/imagemeta)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-8.4|8.5-blue)](composer.json)

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
* XMP `rdf:parseType="Resource"` properties are preserved as structured values under their parent property key instead of flattening child fields into unrelated top-level entries, while `xml:*` qualifiers (for example `xml:lang`) are kept as qualifiers and not exported as standalone properties.
* XMP parser output now preserves RDF container semantics (`rdf:Bag`, `rdf:Seq`, `rdf:Alt`) via explicit container-type metadata in the document model.
* XMP namespace-prefix extraction now preserves default namespace declarations (`xmlns`) as empty-prefix mappings instead of emitting a fake `"xmlns"` prefix.
* ISO BMFF item-based XMP resolution now treats `pitm` only as primary-item identity; XMP prioritization is applied only when the primary item's descriptor explicitly signals XMP content.
* ISO BMFF EXIF selection is now deterministic and `pitm`-aware: primary EXIF items are prioritized among item candidates, and item-based EXIF payloads are ordered before direct `Exif` box payloads.
* ISO BMFF `dref` exports are now `meta`-context scoped, so identical `data_reference_index` values from separate metadata contexts stay distinct and cannot overwrite each other.
* ISO BMFF `iref` exports are now `meta`-context scoped, so identical `item_ID` values from separate metadata contexts remain isolated and cannot be merged ambiguously.
* ISO BMFF unresolved item reports now include the owning `meta` context offset, making same-`item_ID` diagnostics unambiguous across multiple metadata contexts.
* Generic EXIF/TIFF rational conversion now treats only denominator `0` as globally invalid; `-1`/`0xFFFFFFFF` sentinels are interpreted only in tag-specific EXIF paths.
* EXIF `OffsetTime*` timezone parsing in the EXIF API now accepts only canonical numeric offsets (`±HH:MM`) and rejects timezone identifiers like `UTC`, `GMT`, `Europe/Berlin`, and `Z`.
* EXIF offset conversion paths now consistently enforce the `±14:00` maximum across timezone parsing, canonical offset-string normalization, and minute-delta conversion helpers.
* EXIF offset component parsing now rejects non-canonical forms (for example `530`, `5.5`, `+5:30`, minute overflow) and accepts only strict `±HH:MM` inputs across all offset helper APIs.
* Parsed EXIF datetime assembly now validates raw `OffsetTime*` values through the same EXIF offset parser, so identifier/malformed offsets are rejected instead of silently defaulting to UTC.
* Parsed EXIF DateTime* parsing no longer assumes UTC when OffsetTime* is missing; absolute timestamps are emitted only when a valid EXIF offset is present.
* Parsed EXIF DateTime* parsing now rejects calendar/time overflow warnings from PHP datetime normalization (for example month/day/hour overflow) instead of accepting normalized outputs.
* EXIF-conformant JPEG marker-order validation for APP1/APP2/APP11 placement, plus DQT/DHT/DRI/SOF structural ordering and duplicate-marker guards before SOS.
* Strict EXIF-JPEG marker profile now rejects progressive `SOF2`; only baseline `SOF0` is accepted for conformant parsing.
* Strict EXIF-JPEG SOF validation now enforces 8-bit precision, exactly three YCbCr component IDs (`1/2/3`), and legal YCbCr subsampling (`4:2:2`/`4:2:0`).
* JPEG SOF parsing now rejects duplicate component identifiers within a single frame header.
* JPEG marker-flow validation now rejects multiple SOF frame markers before `SOS` and reports both marker offsets.
* JPEG SOS conformance now validates header structure and SOF consistency (component count, duplicate selectors, and unknown selectors) before scan-data parsing.
* JPEG container conformance now requires a valid `EOI` marker after `SOS` scan data and rejects truncated streams that end without `EOI`.
* JPEG scan validation now enforces DRI/RST consistency by requiring restart markers (`RST0..RST7`) in scan data when a `DRI` marker is declared.
* JPEG post-`SOS` scan-data validation now rejects unexpected marker classes (for example `APPn`, `COM`, or second `SOS`) before `EOI`.
* JPEG marker placement now rejects pre-scan restart markers (`RST0..RST7`) before the first `SOS`.
* EXIF marker-profile validation now rejects pre-scan `TEM` markers before the first `SOS`.
* Pre-`SOS` marker-stream parsing now rejects non-marker garbage bytes between marker segments while still accepting legal `0xFF` marker-fill bytes.
* EXIF-bearing JPEG streams now enforce mandatory pre-scan marker groups (`DQT`, `DHT`, `SOF`, `SOS`) and fail fast when any required group is missing.
* EXIF-JPEG cardinality checks now reject duplicate Exif `APP1` metadata blocks (single Exif APP1 only).
* IPTC IIM parsing now validates extended-length headers with strict length-byte-count bounds to reject zero-byte and overlong length encodings.
* APP13 Photoshop resource parsing now requires mandatory even-alignment pad bytes for odd-sized resource names/data and rejects truncated missing-pad payloads.
* APP11/JUMBF metadata extraction now validates transport sequence metadata, reassembles multi-segment payloads per box instance, and surfaces supported XML/XMP payloads while safely skipping unsupported box types.
* EXIF IFD1 JPEG thumbnail validation enforces SOI/EOI boundaries and rejects APPn, COM, and restart markers in strict thumbnail streams.
* JPEG-primary EXIF validation now rejects `IFD0` usage of `JPEGInterchangeFormat` and `JPEGInterchangeFormatLength` (thumbnail-only tags).
* `ParsedExif` now exposes TIFF legacy JPEG tag `JPEGLosslessPredictors` (`0x0205`) via `jpegLosslessPredictors()` for API-complete access alongside neighboring `JPEG*` accessors.
* Strict EXIF camera-control enum-domain validation rejects reserved/out-of-range values for tags such as ExposureProgram, MeteringMode, LightSource, and related controls.
* EXIF `Flash` bitfield parsing now enforces reserved-combination rejection during TIFF/EXIF parsing and exposes typed flash details through `ParsedExif::flashInfo()` while keeping raw `flash()` access.
* EXIF `CompositeImage` now enforces value-domain and companion-tag dependencies (`SourceImageNumberOfCompositeImage`, `SourceExposureTimesOfCompositeImage`) when `CompositeImage=3`.
* EXIF `SourceExposureTimesOfCompositeImage` now enforces strict binary-structure decoding (summary + sequence sections) and rejects truncated/partial payloads as conformance errors.
* TIFF/EXIF `UNDEFINED` values are now kept byte-exact at parser level (including trailing/embedded NUL bytes); any text decoding is handled only by explicit tag-specific converters.
* EXIF GPS coordinate conversion now enforces geographic ranges for capture and destination coordinates (latitude `[-90,90]`, longitude `[-180,180]`) and rejects out-of-domain values.
* EXIF `GPSDateStamp`/`GPSTimeStamp` parsing now enforces semantic UTC validity (real calendar date, hour/minute/second ranges) and rejects invalid timestamps.
* EXIF `GPSTimeStamp` parsing now rejects fractional hour/minute components (only seconds may be fractional), preventing silent truncation/coercion of UTC clock fields.
* EXIF GPS DMS parsing now rejects negative component magnitudes (degrees/minutes/seconds) and requires hemisphere sign handling exclusively via `GPS*Ref` tags.
* EXIF GPS status/reference code fields now enforce strict one-code enum domains and reject reserved multi-character values for both normalized refs and exposed original ref fields.
* EXIF `GPSAltitudeRef` normalization now rejects fractional inputs and accepts only integral enum codes (`0..3`) instead of rounding values into valid-looking references.
* EXIF `UNICODE\0` undefined-text markers now follow EXIF 3.0 UTF-8 semantics across GPS/UserComment paths; malformed UTF-8 is rejected, and BOM-tagged UTF-16 is accepted only as explicit legacy compatibility fallback.
* EXIF `JIS\0\0\0\0\0` undefined-text markers are now decoded with a JIS/ISO-2022-JP strategy (including `ISO-2022-JP-MS` fallback) and no longer default to Shift-JIS/CP932-first behavior.
* EXIF IFD0/IFD1 structure validation now rejects prohibited primary-vs-thumbnail encoding combinations from EXIF Table 3 (for example uncompressed RGB/YCbCr primary with JPEG-compressed thumbnail).
* JPEG APP1 parsing now supports ExtendedXMP chunk reassembly (`xmpNote:HasExtendedXMP`) with strict GUID/offset/coverage validation and deterministic merge into base packets.
* JPEG APP2 FlashPix stream transport now validates per-stream sequence headers strictly (valid `sequence/count`, stable count, no duplicate/missing sequence slots) and fails malformed assemblies with `ParseError`.
* Baseline DNG support for core IFD0 tags: `DNGVersion`, `DNGBackwardVersion`, and `UniqueCameraModel` via `ParsedExif` accessors.
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
$meta->thumbnail->hasThumbnail;
$meta->image->width;
$meta->standards->exifVersion;
```

### Accessing raw building blocks

When working on specialist workflows you can inspect the extracted blobs and parsed representations directly:

```php
<?php
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

$metadata = (new MetadataReader())->read('clip.mov', withDigests: true);

$metadata->mimeType;                          // Detected MIME type
$metadata->digestSha1;                         // Optional SHA-1 digest
$metadata->exifDoc?->makerNotes();             // Vendor maker notes
$metadata->quickTime?->stringValue(QuickTimeMeta::MAJOR_BRAND_KEY);
$metadata->quickTime?->allValues(QuickTimeMeta::CONTENT_IDENTIFIER_KEY); // Ordered data atoms
$metadata->quickTime?->firstAcceptableValue(
    QuickTimeMeta::CONTENT_IDENTIFIER_KEY,
    [0x555315C7, 0], // accepted locales (specific to generic)
    [1],             // accepted type indicators (e.g. UTF-8)
);
$metadata->flashPixStreams;                   // FPXR streams keyed by contents-list index
$metadata->mpfDocument?->entries;              // MP Index entries
$metadata->xmpDoc ?? $metadata->selectiveXmpDocument();
$metadata->structured()->derived->fieldOfViewDiagonalDeg;
```

For multi-track QuickTime/MP4 files, track-derived video/audio keys are taken from the first matching track to avoid order-dependent overwrites.
QuickTime `stsd` video resolution fields are exposed as decoded 16.16 pixels-per-inch values via `QuickTimeMeta::VIDEO_HORIZONTAL_RESOLUTION_KEY` and `QuickTimeMeta::VIDEO_VERTICAL_RESOLUTION_KEY`.
Non-default QuickTime video sample-entry `frame_count` values are exposed via `QuickTimeMeta::VIDEO_FRAME_COUNT_KEY`.

### Reading EXIF only

If you only need EXIF metadata without the structured aggregate, use the EXIF reader facade:

```php
<?php
use MagicSunday\ImageMeta\Convenience\ExifReader;

$exif = (new ExifReader())->read('photo.jpg');

$exif->image->width;
$exif->camera->make;
$exif->exposure->iso;
```

### Custom JPEG parser limits

For JPEG-only workflows you can configure parser guard limits explicitly:

```php
<?php
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParser;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserConfig;

$file = fopen('photo.jpg', 'rb');
if ($file === false) {
    throw new RuntimeException('Unable to open JPEG file.');
}

$stream = new Stream($file, filesize('photo.jpg'));
$parser = new JpegParser(
    $stream,
    new JpegParserConfig(
        maxAppSegmentSize: 1_048_576, // 1 MiB
        flashPixMaxStreamSize: 8_388_608, // 8 MiB
    ),
);

$exifBlobs = $parser->extractExifBlobs();
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
| `multiPicture.entries`          | MP Index IFD entries                           | –                                        | `Factory\Exif\MultiPictureFactory`       |

### Temporal fractional seconds harmonisation

ImageMeta mirrors fractional seconds from `SubSecTimeOriginal` or `SubSecTimeDigitized` into the generic EXIF `SubSecTime` slot when the latter is missing. This display-only harmonisation keeps `temporal.subSecTime`, `temporal.subSecTimeOriginal` and `temporal.subSecTimeDigitized` aligned without altering the underlying capture timestamps.

### GPS metadata coverage

ImageMeta normalises every entry from the EXIF 2.32 table 66 GPS IFD. The decoded data is exposed through `ParsedExif::gps()` and dedicated convenience accessors on `ParsedExif`. The following fields are available to consumers:

* Coordinate references and values: `lat_ref`, `lat`, `lon_ref`, `lon`, `alt_ref`, `alt`.
* Navigation metrics: `speed_ref`, `speed_ms`, `track_ref`, `track`, `img_direction_ref`, `img_direction`, `dest_lat_ref`, `dest_lat`, `dest_lon_ref`, `dest_lon`, `dest_bearing_ref`, `dest_bearing`, `dest_distance_ref`, `dest_distance_m`.
* Metadata, timing and accuracy: `version`, `satellites`, `status`, `measure_mode`, `dop`, `map_datum`, `processing_method`, `area_information`, `date`, `time`, `timestamp`, `differential`, `h_positioning_error`.

All fields are trimmed and converted into PHP primitives (floats, ints, strings or `DateTimeImmutable`) so they can be used directly without consulting tag identifiers.

### EXIF 3.0 environmental and sensor data

EXIF 3.0 introduces environmental and sensor tags for underwater photography, motion tracking, and camera orientation. ImageMeta fully implements these tags through the `Capture` and `Motion` value objects:

* **WaterDepth** (0x9403): Records the depth of the camera below the water surface in metres, accessible via `$s->capture->waterDepthM`.
* **Acceleration** (0x9404): Captures the 3D acceleration vector in m/s². The scalar magnitude is available through `$s->capture->accelerationMs2`, while individual axis components (X, Y, Z) are exposed via `$s->motion->accelX`, `$s->motion->accelY`, and `$s->motion->accelZ`.
* **CameraElevationAngle** (0x9405): Records the camera's elevation angle relative to the horizon in degrees, accessible via `$s->capture->cameraElevationAngleDeg`. Positive values indicate upward tilt, negative values indicate downward tilt.

These tags enable precise documentation of capture conditions in specialised scenarios such as underwater photography, action cameras, and drone imaging. All values are converted from EXIF RATIONAL or SRATIONAL types into native PHP floats with appropriate unit conversions already applied.

### EXIF 3.0 → Value objects

| Value object         | Fields                                                                                                                      | Source tag(s)                                                                                | Converter/Enum                                                               |
|----------------------|-----------------------------------------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| `Interop`            | `index`, `version`                                                                                                          | `InteropIndex`, `InteropVersion`                                                             | Hex fallback for binary data                                                 |
| `TiffData`           | `compression`, `photometric`, `ycbcrSubSampling`, `primaryChromaticities`                                                   | `Compression`, `PhotometricInterpretation`, `YCbCrSubSampling`, `PrimaryChromaticities`      | `Compression`, `Photometric`, `ValueConverters::toPrimaryChromaticities()`   |
| `CompositeImageInfo` | `type`, `counts`, `sourceExposureTimes`                                                                                      | `CompositeImage`, `SourceImageNumberOfCompositeImage`, `SourceExposureTimesOfCompositeImage` | `CompositeImage`, EXIF 3.0 Figure 25 decoder                                          |
| `Standards`          | `exifVersion`, `flashpixVersion`                                                                                            | `ExifVersion`, `FlashpixVersion`                                                             | `ValueConverters::toExifVersion()` (FlashPix defaults to `1.00`)             |
| `Lens`               | `lensSpecification`, `maxApertureFNumber`                                                                                   | `LensSpecification`, `MaxApertureValue`                                                      | `ValueConverters::apexToFNumber()`                                           |
| `Exposure`           | `exposureBiasEv`, `gainControl`, `contrast`                                                                                 | `ExposureBiasValue`, `GainControl`, `Contrast`                                               | `GainControl` enum                                                           |
| `Scene`              | `subjectDistanceRange`                                                                                                      | `SubjectDistanceRange`                                                                       | `SubjectDistanceRange` enum                                                  |
| `Device`             | `rawDevelopingSoftware`, `imageEditingSoftware`, `metadataEditingSoftware`                                                  | `RAWDevelopingSoftware`, `ImageEditingSoftware`, `MetadataEditingSoftware`                   | –                                                                            |
| `Capture`            | `dateTime`, `temperatureC`, `humidityPercent`, `pressureHPa`, `waterDepthM`, `accelerationMs2`, `cameraElevationAngleDeg`   | `Temperature`, `Humidity`, `Pressure`, `WaterDepth`, `Acceleration`, `CameraElevationAngle`  | `ValueConverters::rationalToFloat()`, `sqrt()` for acceleration magnitude    |
| `Motion`             | `accelX`, `accelY`, `accelZ`                                                                                                | `Acceleration` (SRATIONAL triplet)                                                           | `ValueConverters::srationalTripletToFloatVector()`                           |
| `Gps`                | `latitude`, `longitude`, `altitude`, `speed*`, `track*`, `timestamp`, navigation metadata                                   | GPS IFD (`GPS*`) with XMP `exif:GPS*` fallbacks                                              | `ValueConverters::gpsFromIfd()`, harmonisation in `Factory\Exif\ValueFactory` |
| `MultiPicture`       | `version`, `imageCount`, `entries`, `totalFrames`, `individualImageNumber`, `imageUidList`, `panoramaAngle`, `panoramaAxis` | MP Index IFD, MP Attribute IFD                                                               | `Factory\Exif\MultiPictureFactory`                                            |

```php
$s = $meta->structured();
$s->tiff->compression;              // Compression::JPEG
$s->lens->lensSpecification;       // [minF, minAperture, maxF, maxAperture]
$s->composite->type;                // CompositeImage::GeneralComposite
$s->standards->exifVersion;          // "3.00"
$s->capture->waterDepthM;           // 5.2 (metres below water surface)
$s->capture->accelerationMs2;       // 9.8 (m/s² magnitude)
$s->capture->cameraElevationAngleDeg; // 15.5 (degrees relative to horizon)
$s->motion->accelX;                 // 0.5 (X-axis acceleration component)
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
