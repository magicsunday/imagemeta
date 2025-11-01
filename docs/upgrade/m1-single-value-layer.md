## Impacted APIs

Milestone M1 removed the transitional wrapper classes that previously lived under
`MagicSunday\\ImageMeta\\Curate\\Exif\\Structured`. `StructuredExif` now exposes the
immutable value objects from `MagicSunday\\ImageMeta\\Value` directly so API consumers
no longer need to hop through intermediate wrappers.

Removed wrappers:

- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Camera`
- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Lens`
- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Exposure`
- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Gps`
- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Image`
- `MagicSunday\\ImageMeta\\Curate\\Exif\\Structured\\Preview`

`MagicSunday\\ImageMeta\\Exif\\StructuredExif` continues to return the same value
objects as the curated metadata aggregate so existing integrations can migrate at
their own pace.

## Migration Guidance

1. Prefer `Metadata::structured()` which returns
   `MagicSunday\\ImageMeta\\Curate\\Structured\\StructuredMetadata`.
2. Access curated value objects directly, for example:
   ```php
   $metadata = (new MetadataReader())->read($path)->structured();
   $metadata->camera->model;
   $metadata->lens->focalLengthMm;
   $metadata->image->width;
   $metadata->gps->latitude;
   ```
3. For preview information, use `$metadata->preview` instead of the removed
   `Structured\\Preview` wrapper.

## Deprecation Mechanics

- Deprecations were expressed via PHPDoc `@deprecated` annotations with internal
  wording to avoid accidental public API promotion.
- No runtime triggers were emitted; consumers had to rely on static analysis or
  IDE inspections.
- Removal took place once library consumers confirmed migration to the curated
  aggregates.
