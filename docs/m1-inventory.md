# Milestone M1 Inventory

This inventory documents every reference to the legacy structured EXIF wrappers and the curated metadata aggregates. It highlights call sites that need to be migrated while converging on a single value layer.

## `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\*`

| Wrapper | Location | Purpose |
| --- | --- | --- |
| `Camera` | `src/Exif/StructuredExif.php` | Imported and instantiated inside `StructuredExif` to expose EXIF-only camera metadata without cross-container fallbacks. |
| `Lens` | `src/Exif/StructuredExif.php` | Instantiated by `StructuredExif` to wrap the derived lens value objects. |
| `Exposure` | `src/Exif/StructuredExif.php` | Created by `StructuredExif` to surface exposure settings alongside derived EV helpers. |
| `Gps` | `src/Exif/StructuredExif.php` | Provides EXIF-only GPS data for the legacy `StructuredExif` aggregate. |
| `Image` | `src/Exif/StructuredExif.php` | Supplies image dimension accessors required by the EXIF wrapper. |
| `Preview` | `src/Exif/StructuredExif.php`, `tests/Support/ExifExpectationAssertions.php` | Used by the API wrapper and expectation helpers to expose thumbnail and preview metadata. |

## `MagicSunday\\ImageMeta\\Curate\\Structured\\*`

| Aggregate | Location | Purpose |
| --- | --- | --- |
| `StructuredMetadata` | `src/Curate/StructuredMetadata.php` | Central aggregate composed of curated value objects spanning file, camera, lens, exposure, GPS, sensor, processing, technical and rights metadata. |
| `CameraMetadata`, `LensMetadata`, `ExposureMetadata`, `GpsMetadata` | `src/Curate/StructuredMetadata.php`, `src/Curate/Exif/ValueFactory.php` | Value-object aggregates assembled by `ValueFactory` and injected into `StructuredMetadata`. |
| `CaptureMetadata`, `MediaMetadata`, `ProcessingMetadata`, `SensorMetadata`, `TechnicalMetadata`, `RightsMetadata`, `MakerNotesView`, `FileMetadata` | `src/Curate/StructuredMetadata.php`, `src/Curate/Exif/ValueFactory.php` | Additional grouped metadata slices created during curation and exposed through the structured aggregate. |

## `MagicSunday\\ImageMeta\\Convenience\\ExifConvenience::gps()`

* Defined in `src/Convenience/ExifConvenience.php` where it forwards to `ParsedExif::gps()` while retaining EXIF specification references.
* No external call sites were found during the repository scan, indicating that the helper currently lacks dedicated consumers or tests.
