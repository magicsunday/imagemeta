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
| `$meta->lens()->lens()` | `$meta->lens()` |
| `$meta->lens()->equivalent35mm()` | `$meta->lens()->focalLengthIn35mm ?? $meta->derived()->equivalent35mm()` |
| `$meta->exposure()->exposure` | `$meta->exposure()` |
| `$meta->capture()->temporal` | `$meta->temporal()` |
| `$meta->capture()->regions` | `$meta->regions()` |
| `$meta->media()->image` | `$meta->image()` |
| `$meta->media()->preview` | `$meta->preview()` |
| `$meta->media()->composite` | `$meta->composite()` |
| `$meta->technical()->interop` | `$meta->interop()` |
| `$meta->technical()->tiff` | `$meta->tiff()` |
| `$meta->technical()->standards` | `$meta->standards()` |
| `$meta->rights()->author` | `$meta->author()` |
| `$meta->rights()->related` | `$meta->related()` |
| `$meta->makerNotes()->apple` | `$meta->makerNotesApple()` |

### GPS helpers

The GPS value object continues to provide decoded coordinate helpers. The
deprecated `dop()` accessor has been removed; use `dilutionOfPrecision()` instead.
Coordinate objects are now available via `latitudeCoordinate()` and
`longitudeCoordinate()`.

### StructuredExif compatibility

`MagicSunday\ImageMeta\Exif\StructuredExif` now returns the same value objects as
the curated metadata aggregate. Update API usages as follows:

| Removed API | Replacement |
| --- | --- |
| `$document->camera()->value()` | `$document->camera()` |
| `$document->lens()->value()` | `$document->lens()` |
| `$document->lens()->focalLength()` | `$document->lens()->focalLengthMm()` |
| `$document->lens()->derived()` | `$document->derived()` |
| `$document->gps()->latitude()` | `$document->gps()->latitudeCoordinate()` |
| `$document->image()->value()` | `$document->image()` |
| `$document->preview()->value()` | `$document->preview()` |

The class remains for backward compatibility but will be removed in a later
milestone. Consumers should migrate to `Metadata::structured()`.

### Static analysis

PHPStan continues to run at maximum level. Generics have been tightened to use
specific `list<...>` shapes where arrays are exposed from value objects and
factories.
