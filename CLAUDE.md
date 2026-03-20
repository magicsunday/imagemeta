# CLAUDE.md — MagicSunday/ImageMeta

## What is this?

PHP library (`magicsunday/imagemeta`) for read-only metadata extraction from JPEG, ISO BMFF (HEIC, AVIF, MOV, MP4), TIFF, and JXL containers. Parses EXIF, XMP, IPTC, QuickTime metadata and exposes both raw models and typed structured output.

- **Main API:** `MagicSunday\ImageMeta\MetadataReader`
- **Output:** `Model\Metadata` (raw) + `Value\StructuredMetadata` (typed)
- **PHP:** `>=8.4.0 <8.6.0`

## Running CI

This project has its own Docker buildbox (`Dockerfile` + `compose.yaml`). All commands are available via `make`:

```bash
make test           # Full CI (mandatory gate before any commit)
make lint           # PHP linter
make cgl-check      # Code style check (dry-run)
make rector-check   # Rector check (dry-run)
make stan           # PHPStan analysis
make unit           # PHPUnit tests
make cpd            # Copy-paste detection (jscpd)
make cgl            # Fix code style
make rector         # Apply rector rules
make install        # Install composer dependencies
make update         # Update composer dependencies
make format FILE=<path>  # Run format script
make build          # Build Docker image
make bash           # Open a shell in the buildbox container
make help           # Show all available targets
```

CI pipeline order: phplint -> php-cs-fixer (dry-run) -> rector (dry-run) -> phpstan -> phpunit -> jscpd

## Code Style

- `declare(strict_types=1)` in every PHP file
- PSR-12 + `@Symfony` ruleset via php-cs-fixer
- `use function` imports for all PHP built-in functions (no inline `\strlen()`)
- `final readonly class` for value objects
- One class per file
- `++$i` pre-increment style (enforced by php-cs-fixer)
- Binary operator alignment: `=` and `=>` use `align_single_space_minimal`
- Non-Yoda comparisons (`$x === null`, not `null === $x`)
- No `mixed` type, no `empty()`, no nested ternaries
- In compound conditions (`&&`/`||`), wrap comparison operands in parentheses: `if (($x <= 0.0) || !is_finite($x))`
- PHPDoc `@param`/`@return` columns must align (phpdoc_align)

## PHPStan

- Level: `max`
- Includes strict-rules, deprecation-rules, phpunit extensions
- Use `@phpstan-type` aliases in class docblocks for complex shapes
- Avoid `assertInstanceOf` when PHPStan already narrows the type
- Use `instanceof` checks instead of nullsafe `?->` with `??` when PHPStan flags it

## PHPUnit

- PHPUnit 12 with attributes: `#[Test]`, `#[CoversClass]`, `#[UsesClass]`, `#[UsesTrait]`
- CamelCase test method names (no underscores)
- Prefer synthetic binary data over real media fixtures
- Tests build box structures using `box()`/`fullBox()` helpers
- Every behavior: at least 1 positive + 1 negative test (`ParseError`/`BoundsError`)

## Rector

- Sets: CODE_QUALITY, CODING_STYLE, DEAD_CODE, EARLY_RETURN, INSTANCEOF, PRIVATIZATION, TYPE_DECLARATION, UP_TO_PHP_84
- Watch for: `SimplifyQuoteEscapeRector`, `FlipTypeControlToUseExclusiveTypeRector`, `RemoveUnusedPrivateMethodParameterRector`

## Git & Commits

- Commit directly on `main` (no branches)
- Commit message format: `GH-<number>: <Message starting with capital letter>`
- **No** `Co-Authored-By` trailers
- **No** GH-XXXX references in code comments — only in commit messages
- **No** Conventional Commits prefixes (`feat:`, `fix:`, etc.)
- When `ci:test` is green and commit references an issue: close the issue immediately
- CGL alignment fixes in **separate commits** from feature changes (php-cs-fixer cascades across hundreds of files)

## TDD

- **Test-driven development is mandatory** for all feature and bugfix work
- Write a failing test first, then implement the minimal code to make it pass
- For refactorings: verify existing tests pass before changing code

## Architecture

- **Separation of concerns:** Detect != Parse != Model != Convenience != Value
- Parsers parse, models hold data, factories create
- Vendor-specific logic (DJI, Apple, Samsung) belongs in dedicated classes, not in general parsers
- No speculative abstractions; extract helpers only after 3+ real duplicates
- Constants/enums over magic numbers
- Errors via exceptions only: `ParseError` (unique numeric codes) and `BoundsError`
- Streaming-based parsing; no full-buffer materialization
- **Parser tolerance (Postel's Law):** Tolerate non-zero reserved fields, non-standard flags, and padding. Only reject when data is genuinely unparseable (truncated, zero timescale, etc.). When a real file fails, check ExifTool behavior as reference before adding strict validation.
- **StructuredMetadata fallback chain:** Factories must aggregate all sources: EXIF → XMP → QuickTime. Users should get complete metadata without knowing which source holds it.

## Specifications

All format behavior must be derived from specs in `docs/`:

- EXIF 3.1: `docs/EXIF-310.pdf`
- EXIF 3.0: `docs/EXIF-300.pdf`
- TIFF 6.0: `docs/TIFF6.pdf`
- ISO BMFF: `docs/ISO_IEC_14496-12_2015.pdf`
- QuickTime: `docs/Quicktime-File-Format-2012.pdf`
- XMP: `docs/XMP.pdf`
- ICC: `docs/ICC.pdf`
- MPF: `docs/MPF.pdf`
- DNG: `docs/DNG_Spec_1_7_1_0.pdf`

Cite specs inline: `EXIF 3.0 §4.6.4`. Prefer HTML for search, PDF for authoritative wording.

## Project Layout

```
src/
  Contract/          # Parser interfaces
  Convenience/       # High-level reader wrappers
  Core/              # Stream, ByteReader, BoundsError, ParseError, Util/
  Detect/            # Container type detection (signature-based)
  Exif/              # EXIF parsing, adapters, converters, text formatting
  Factory/           # StructuredMetadata builder
  MakerNotes/        # Vendor MakerNote decoders (Apple, Samsung)
  Model/             # Data models (EXIF tags, IPTC, ICC, QuickTime, XMP, DJI, etc.)
  Parse/             # Parsers: Icc/, Iptc/, IsoBmff/, Jpeg/, Jxl/, Tiff/, Xmp/
  Value/             # Typed structured value objects (Camera, Gps, Exposure, etc.)
  MetadataReader.php # Main entry point
tests/               # Mirrors src/ structure; synthetic binary test data
scripts/             # CLI tools (imagemeta-format.php, validate-test-images.php)
docs/                # Format specifications (HTML + PDF)
```
