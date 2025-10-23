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

The aggregate always instantiates each value object. Consumers therefore never have to deal with tag identifiers or container-specific key names.
