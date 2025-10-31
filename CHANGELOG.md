# Changelog

## Unreleased

### Added
- Added `Value\Derived::fieldOfViewHorizontalDeg` and `Value\Derived::fieldOfViewVerticalDeg` to expose axis specific angles of view alongside the
  diagonal `fieldOfViewDiagonalDeg` helper.
- Extended `Value\Temporal` with offset tags, a resolved `DateTimeZone` instance and minute level EXIF offsets for reliable
  capture time reconstruction.
- Enriched `Value\File` with mime type, file size, extension and SHA-1/MD5 digests so that consumers can correlate assets without
  re-reading the original payload.
- Broadened the Apple aggregate with semantic style parameters, colour temperature in Kelvin and person/pet detection flags
  sourced from maker notes and QuickTime metadata.
- Expanded GPS coverage to include horizontal positioning error and the full destination navigation set from EXIF 2.32 table 66.
- Introduced dedicated maker note decoders for Apple (`MakerNotes\AppleDecoder`), Canon, Nikon and Sony plus the
  `Curate\Resolver\MakerNotesResolver` convenience helper.
- Added best-effort EXIF fallbacks that resolve ISO sensitivity, capture timestamps, and user comment encodings from SubIFDs and
  primary thumbnails, including timezone offsets carried outside the main EXIF directory.

### Changed
- Renamed derived field helpers to `equivalent35mm`, `fieldOfView*` and `hyperfocalDistanceMetres`; legacy accessors remain as deprecated aliases.
- Backfilled structured JPEG image bit depth from the start-of-frame precision when the EXIF tag is missing.

### Removed
- Dropped the implicit QuickTime video metadata fallback to avoid mixing still-image data with movie track information.
  Clients that require video track information should rely on the explicit `Video` aggregate.
