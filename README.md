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
```

### Mapping overview

| Field | Primary source | Fallback | Converter |
| --- | --- | --- | --- |
| `camera.make` | EXIF `Make` | XMP `tiff:Make` | – |
| `lens.model` | EXIF `LensModel` | – | – |
| `exposure.flash` | EXIF `Flash` | XMP `exif:Flash` | `ValueConverters::flashFromShort()` |
| `temporal.original` | EXIF `DateTimeOriginal` + `OffsetTimeOriginal` | XMP `exif:DateTimeOriginal` | `ValueConverters::parseOffset()` |
| `derived.ev100` | Calculated from exposure values | – | `ValueConverters::calcEv100()` |

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

> [!NOTE]
> The legacy accessors `lensInfo`, `compositeImageCount` and `compositeExposureTimes` remain available as deprecated aliases to
> ease migration to the EXIF 3.0 naming scheme and will be removed in a future major release.

The aggregate always instantiates each value object. Consumers therefore never have to deal with tag identifiers or container-specific key names.
