# Milestone M1 Inventory

This inventory tracks the migration towards a single structured value layer and
documents the remaining touch points after removing the legacy wrappers.

## Removed structured EXIF wrappers

* No PHP files remain under `src/Curate/Exif/Structured`; the namespace was
  deleted as part of the wrapper removal.
* `src/Exif/StructuredExif.php` instantiates `MagicSunday\\ImageMeta\\Value`
  objects directly (`Camera`, `Lens`, `Exposure`, `Gps`, `Image`, `Preview`).
* `tests/Support/ExifExpectationAssertions.php` exercises these value objects
  without intermediate wrappers, ensuring API expectations stay aligned with the
  curated aggregates.

## Structured metadata value layer

| Aggregate | Location | Purpose |
| --- | --- | --- |
| `StructuredMetadata` | `src/Curate/StructuredMetadata.php` | Central aggregate composed of curated value objects spanning file, camera, lens, exposure, GPS, sensor, processing, technical and rights metadata. |
| `Camera`, `Lens`, `Exposure`, `Gps` | `src/Curate/StructuredMetadata.php`, `src/Curate/Exif/ValueFactory.php` | Immutable value objects assembled by `ValueFactory` and exposed directly via `StructuredMetadata`. |
| `Capture`, `Scene`, `Temporal`, `Regions`, `Keywords`, `Sensor`, `Focus`, `Motion`, `Uav`, `ProcessingSettings`, `WhiteBalanceDetails`, `Interop`, `TiffData`, `Standards`, `FlashPix`, `Xmp`, `Rights`, `Author`, `RelatedAssets`, `Container`, `Integrity`, `Preview`, `Image`, `Video`, `Audio`, `AudioClips`, `ColorProfile`, `CompositeImageInfo`, `MultiPicture`, `Derived` | `src/Curate/StructuredMetadata.php`, `src/Curate/Exif/ValueFactory.php` | Additional metadata slices exposed through dedicated getters on `StructuredMetadata`. |

## Convenience helpers

* `src/Convenience/ExifConvenience.php` exposes presentation helpers for
  formatted strings (`cameraDescription`, `exposureSummary`, `gpsString`,
  `imageDimensions`, `captureDateTimeString`, `toArray`). The helper only
  formats data supplied via value objects; it does not perform parsing or tag
  resolution.
* `tests/Convenience/ExifConvenienceTest.php` covers formatting behaviour and
  ensures the helper remains side-effect free.
