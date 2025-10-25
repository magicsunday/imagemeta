# MagicSunday/ImageMeta

MagicSunday/ImageMeta provides a streaming metadata parser for JPEG, HEIC and ISO Base Media File Format containers. It unifies EXIF, XMP and QuickTime sources into a common PHP domain model.

## Structured Metadata API

The library exposes a structured and fully typed metadata aggregate via `Metadata::structured()`. The aggregate wraps immutable value objects that hide any tag or container specifics.

```php
<?php
use MagicSunday\ImageMeta\MetadataReader;

$meta = (new MetadataReader())->read('photo.heic');
$structured = $meta->structured();

$structured->lens->model;
$structured->temporal->original;
$structured->keywords->flat;
$structured->derived->ev100;
$structured->related->livePhotoPairId;
$structured->derived->fovDiagonalDeg;     // Diagonal field of view (formerly fovDeg)
$structured->derived->fovHorizontalDeg;   // Horizontal field of view in degrees
$structured->derived->fovVerticalDeg;     // Vertical field of view in degrees
$structured->temporal->tz?->getName();    // Resolved capture time zone source
$structured->temporal->offsetTimeOriginal;
$structured->file->fileSize;              // File level metadata (size, digests, extension)
$structured->apple->flags['livePhotoEnabled'];
$structured->gps->horizontalPositioningError;
```

### Mapping overview

| Field | Primary source | Fallback | Converter |
| --- | --- | --- | --- |
| `camera.make` | EXIF `Make` | XMP `tiff:Make` | – |
| `lens.model` | EXIF `LensModel` | – | – |
| `exposure.flash` | EXIF `Flash` | XMP `exif:Flash` | `ValueConverters::flashFromShort()` |
| `temporal.original` | EXIF `DateTimeOriginal` + `OffsetTimeOriginal` | XMP `exif:DateTimeOriginal` | `ValueConverters::parseOffset()` |
| `derived.ev100` | Calculated from exposure values | – | `ValueConverters::calcEv100()` |

### Temporal fractional seconds harmonisation

ImageMeta mirrors fractional seconds from `SubSecTimeOriginal` or `SubSecTimeDigitized` into the generic EXIF `SubSecTime` slot when the latter is missing. This display-only harmonisation keeps `temporal.subSecTime`, `temporal.subSecTimeOriginal` and `temporal.subSecTimeDigitized` aligned without altering the underlying capture timestamps.

### GPS metadata coverage

ImageMeta normalises every entry from the EXIF 2.32 table 66 GPS IFD. The decoded data is exposed through
`ExifDocument::gps()` and the convenience accessors on `Curate\Resolver\ExifTagResolver`. The following fields are
available to consumers:

* Coordinate references and values: `lat_ref`, `lat`, `lon_ref`, `lon`, `alt_ref`, `alt`.
* Navigation metrics: `speed_ref`, `speed_ms`, `track_ref`, `track`, `img_direction_ref`, `img_direction`,
  `dest_lat_ref`, `dest_lat`, `dest_lon_ref`, `dest_lon`, `dest_bearing_ref`, `dest_bearing`, `dest_distance_ref`,
  `dest_distance_m`.
* Metadata, timing and accuracy: `version`, `satellites`, `status`, `measure_mode`, `dop`, `map_datum`,
  `processing_method`, `area_information`, `date`, `time`, `timestamp`, `differential`, `h_positioning_error`.

All fields are trimmed and converted into PHP primitives (floats, ints, strings or `DateTimeImmutable`) so they can be
used directly without consulting tag identifiers.

### EXIF 3.0 → Value objects

| Value object | Fields | Source tag(s) | Converter/Enum |
| --- | --- | --- | --- |
| `Interop` | `index`, `version` | `InteropIndex`, `InteropVersion` | Hex fallback for binary data |
| `TiffData` | `compression`, `photometric`, `ycbcrSubSampling`, `primaryChromaticities` | `Compression`, `PhotometricInterpretation`, `YCbCrSubSampling`, `PrimaryChromaticities` | `Compression`, `Photometric`, `ValueConverters::toPrimaryChromaticities()` |
| `CompositeImageInfo` | `type`, `counts`, `exposureTimesTotal` | `CompositeImage`, `SourceImageNumberOfCompositeImage`, `SourceExposureTimesOfCompositeImage` | `CompositeImage`, rational to float |
| `Standards` | `exifVersion`, `flashpixVersion` | `ExifVersion`, `FlashpixVersion` | `ValueConverters::toExifVersion()` |
| `Lens` | `lensSpecification`, `maxApertureFNumber` | `LensSpecification`, `MaxApertureValue` | `ValueConverters::apexToFNumber()` |
| `Exposure` | `exposureBiasEv`, `gainControl`, `contrast` | `ExposureBiasValue`, `GainControl`, `Contrast` | `GainControl` enum |
| `Scene` | `subjectDistanceRange` | `SubjectDistanceRange` | `SubjectDistanceRange` enum |
| `Device` | `rawDevelopingSoftware`, `imageEditingSoftware`, `metadataEditingSoftware` | `RAWDevelopingSoftware`, `ImageEditingSoftware`, `MetadataEditingSoftware` | – |

```php
$s = $meta->structured();
$s->tiff->compression;              // Compression::JPEG
$s->lens->lensSpecification;       // [minF, minAperture, maxF, maxAperture]
$s->composite->type;                // CompositeImage::GeneralComposite
$s->standards->exifVersion;         // "3.00"
```

The aggregate always instantiates each value object. Consumers therefore never have to deal with tag identifiers or container-specific key names.

The diagonal field of view exposed via `fovDiagonalDeg` corresponds to the value previously documented as `fovDeg`. The new `fovHorizontalDeg` and `fovVerticalDeg` helpers provide axis-specific angles so clients can present per-dimension compositions without additional trigonometry.

The expanded temporal aggregate surfaces raw EXIF offset tags alongside a resolved `DateTimeZone` instance. This makes it possible to reconstruct original capture times even when the offset varies between creation, digitisation and modification steps. File level metadata now reports size, extension and cryptographic digests to help consumers correlate assets or detect tampering.

Apple maker note data now includes semantic style parameters, colour temperature and Live Photo flags from both maker notes and QuickTime metadata. GPS coverage has been widened with horizontal positioning error and full destination navigation metrics from EXIF 2.32 table 66.

Bit-mask sources such as `SceneFlags`, `ImageProcessingFlags` and `PhotosAppFeatureFlags` are decoded so their individual bits populate the normalised `apple.flags` array:

* `SceneFlags`: bit 0 → `nightMode`, bit 1 → `longExposure`
* `ImageProcessingFlags`: bit 0 → `hdrEnabled`, bit 1 → `hdrAuto`
* `PhotosAppFeatureFlags`: any non-zero value indicates `personOrPetDetected`

Explicit boolean keys continue to override the derived values when both representations are present.
