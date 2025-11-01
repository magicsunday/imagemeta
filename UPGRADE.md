# Upgrade Guide

## M8 – Structured metadata cleanup

Milestone M8 removes the remaining transitional getters from the curated metadata aggregate and the legacy `StructuredExif` wrapper. Public readonly properties are now the only access surface, so integrations must rely on property chains instead of pass-through methods.

### StructuredMetadata

| Removed call | Replacement |
| --- | --- |
| `$meta->file()->mimeType` | `$meta->file->mimeType` |
| `$meta->container()->encoder` | `$meta->container->encoder` |
| `$meta->capture()->temperatureC` | `$meta->capture->temperatureC` |
| `$meta->scene()->nightMode` | `$meta->scene->nightMode` |
| `$meta->temporal()->original` | `$meta->temporal->original` |
| `$meta->sensor()->spatialFrequencyResponse` | `$meta->sensor->spatialFrequencyResponse` |
| `$meta->makerNotesApple()` | `$meta->makerNotesApple` |

### StructuredExif

| Removed call | Replacement |
| --- | --- |
| `$document->camera()->make` | `$document->camera->make` |
| `$document->lens()->focalLengthMm` | `$document->lens->focalLengthMm` |
| `$document->exposure()->shutterSpeed` | `$document->exposure->shutterSpeed` |
| `$document->gps()->latitudeCoordinate` | `$document->gps->latitudeCoordinate` |
| `$document->preview()->hasThumbnail` | `$document->preview->hasThumbnail` |
| `$document->iso()` | `$document->iso` |
| `$document->dateTimeOriginal()` | `$document->dateTimeOriginal` |
| `$document->userComment()` | `$document->userComment` |

### Value objects

The value layer continues to expose public readonly properties. Milestone M8 removes any remaining legacy getters so consumers should read fields directly, for example `$image->width`, `$camera->model`, `$exposure->iso` and `$preview->hasThumbnail`.

### GPS helpers

The GPS value object retains derived helpers as properties. Former accessor methods such as `dilutionOfPrecision()` and `dop()` are now exposed as `$gps->dop`. The `$gps->timestamp` property always contains a UTC-normalised `DateTimeImmutable` when present.

### StructuredExif compatibility

`MagicSunday\ImageMeta\Exif\StructuredExif` remains available for backwards compatibility and now mirrors the curated aggregate's property access. Continue migrating to `Metadata::structured()` to prepare for its removal in a future milestone.

### Static analysis

PHPStan continues to run at maximum level. Generics have been tightened to use
specific `list<...>` shapes where arrays are exposed from value objects and
factories.
