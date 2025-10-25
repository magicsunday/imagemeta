# Changelog

## Unreleased

### Added
- Added `Value\Derived::fovHorizontalDeg` and `Value\Derived::fovVerticalDeg` to expose axis specific angles of view alongside the
  diagonal `fovDiagonalDeg` helper.
- Extended `Value\Temporal` with offset tags, a resolved `DateTimeZone` instance and minute level EXIF offsets for reliable
  capture time reconstruction.
- Enriched `Value\File` with mime type, file size, extension and SHA-1/MD5 digests so that consumers can correlate assets without
  re-reading the original payload.
- Broadened the Apple aggregate with semantic style parameters, colour temperature in Kelvin and person/pet detection flags
  sourced from maker notes and QuickTime metadata.
- Expanded GPS coverage to include horizontal positioning error and the full destination navigation set from EXIF 2.32 table 66.
- Introduced dedicated maker note decoders for Apple (`MakerNotes\AppleDecoder`), Canon, Nikon and Sony plus the
  `Curate\Resolver\AppleResolver` and `Curate\Resolver\MakerNotesResolver` convenience helpers.

### Changed
- Renamed the diagonal field-of-view helper from `fovDeg` to `fovDiagonalDeg`; consumers should switch to the new property name.

### Removed
- Dropped the implicit QuickTime video metadata fallback to avoid mixing still-image data with movie track information.
  Clients that require video track information should rely on the explicit `Video` aggregate.
