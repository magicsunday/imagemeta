# Milestone M1 Upgrade Notes — Single Value Layer

Milestone M1 introduces a single structured metadata layer backed by immutable value objects. The EXIF-only wrapper classes under `MagicSunday\ImageMeta\Curate\Exif\Structured` remain functional for the current release but are marked as internal deprecations to guide integrators towards the curated aggregates.

## Impacted APIs

The following classes are tagged with `@deprecated` and will be removed after Milestone M1:

- `MagicSunday\ImageMeta\Curate\Exif\Structured\Camera`
- `MagicSunday\ImageMeta\Curate\Exif\Structured\Lens`
- `MagicSunday\ImageMeta\Curate\Exif\Structured\Exposure`
- `MagicSunday\ImageMeta\Curate\Exif\Structured\Gps`
- `MagicSunday\ImageMeta\Curate\Exif\Structured\Image`
- `MagicSunday\ImageMeta\Curate\Exif\Structured\Preview`

`MagicSunday\ImageMeta\Exif\StructuredExif` continues to expose these wrappers so existing integrations remain operational during the transition.

## Migration Guidance

1. Prefer `Metadata::structured()` which returns `MagicSunday\ImageMeta\Curate\Structured\StructuredMetadata`.
2. Access curated value objects directly, for example:
   ```php
   $metadata = (new MetadataReader())->read($path)->structured();
   $metadata->camera->model;
   $metadata->lens->focalLengthMm;
   $metadata->media()->image->width;
   $metadata->gps->latitudeCoordinate()?->toFloat();
   ```
3. For preview information, use `$metadata->media()->preview` instead of the deprecated `Structured\Preview` wrapper.

## Deprecation Mechanics

- Deprecations are expressed via PHPDoc `@deprecated` annotations with internal wording to avoid accidental public API promotion.
- No runtime triggers are emitted; consumers must rely on static analysis or IDE inspections.
- Removal is scheduled after Milestone M1 once library consumers confirm migration to the curated aggregates.
