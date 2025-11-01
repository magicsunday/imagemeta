# Upgrade Guide

## M8 – Structured metadata cleanup

Milestone M8 removes the transitional wrapper classes that previously lived under
`MagicSunday\ImageMeta\Curate\Exif\Structured`. Structured metadata now exposes the
immutable value objects directly. Integrations must adjust method chains as shown
in the following table:

| Removed API | Replacement |
| --- | --- |
| `$meta->file()->file()` | `$meta->file()` |
| `$meta->file()->container` | `$meta->container()` |
| `$meta->camera()->device` | `$meta->device()` |
| `$meta->lens()->lens()` | `$meta->lens` |
| `$meta->lens()->equivalent35mm()` | `$meta->lens->focalLengthIn35mm ?? $meta->derived->equivalent35mm` |
| `$meta->exposure()->exposure` | `$meta->exposure` |
| `$meta->capture()->temporal` | `$meta->temporal()` |
| `$meta->capture()->regions` | `$meta->regions()` |
| `$meta->media()->image` | `$meta->image` |
| `$meta->media()->preview` | `$meta->preview` |
| `$meta->media()->composite` | `$meta->composite()` |
| `$meta->technical()->interop` | `$meta->interop` |
| `$meta->technical()->tiff` | `$meta->tiff()` |
| `$meta->technical()->standards` | `$meta->standards` |
| `$meta->rights()->author` | `$meta->author()` |
| `$meta->rights()->related` | `$meta->related()` |
| `$meta->makerNotes()->apple` | `$meta->makerNotesApple()` |

### Value objects

All value objects under `MagicSunday\\ImageMeta\\Value` now expose public readonly
properties. Legacy getters such as `$image->width()` or `$camera->model()` have
been removed. Update integrations to read the properties directly, e.g.
`$image->width`, `$camera->model`, `$exposure->iso` and `$preview->hasThumbnail`.

### GPS helpers

The GPS value object retains derived helpers but now surfaces them as properties.
Former accessors like `dilutionOfPrecision()` and `dop()` have been removed; use
`$gps->dop` directly. Coordinate helpers are available via properties such as
`$gps->latitudeCoordinate` and `$gps->longitudeCoordinate`. The `$gps->timestamp`
property always contains a UTC-normalised `DateTimeImmutable` when present.

### StructuredExif compatibility

`MagicSunday\ImageMeta\Exif\StructuredExif` now returns the same value objects as
the curated metadata aggregate. Update API usages as follows:

| Removed API | Replacement |
| --- | --- |
| `$document->camera()->value()` | `$document->camera()` |
| `$document->lens()->value()` | `$document->lens()` |
| `$document->lens()->focalLength()` | `$document->lens()->focalLengthMm` |
| `$document->lens()->derived()` | `$document->derived()` |
| `$document->gps()->latitude()` | `$document->gps()->latitudeCoordinate` |
| `$document->image()->value()` | `$document->image()` |
| `$document->preview()->value()` | `$document->preview()` |

The class remains for backward compatibility but will be removed in a later
milestone. Consumers should migrate to `Metadata::structured()`.

### Static analysis

PHPStan continues to run at maximum level. Generics have been tightened to use
specific `list<...>` shapes where arrays are exposed from value objects and
factories.
