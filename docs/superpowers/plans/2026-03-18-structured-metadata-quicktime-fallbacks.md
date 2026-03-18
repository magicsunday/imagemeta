# StructuredMetadata QuickTime Fallbacks Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `StructuredMetadata` return complete metadata for MOV/MP4/HEIC files by using QuickTime data as fallback when EXIF/XMP is absent.

**Architecture:** Each factory that builds a `StructuredMetadata` component gets a QuickTime fallback tier. Factories already accept `Metadata` which carries `?QuickTimeMeta`. The existing `QuickTimeLookup` bridge class provides type-safe access. The fallback priority is always: EXIF → XMP → QuickTime. New fields (`rotation`, `bitDepth`) are added to the `Video` value object. An ISO 6709 parser is added for `©xyz` GPS coordinates.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan max level, `QuickTimeLookup` bridge pattern.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Value/Video.php` | Modify | Add `rotation` and `bitDepth` fields |
| `src/Exif/Factory/ValueFactory.php` | Modify | Pass rotation + bitDepth to Video constructor |
| `src/Exif/Factory/CameraFactory.php` | Modify | Add QuickTime make/model fallback |
| `src/Exif/Factory/ImageFactory.php` | Modify | Add QuickTime width/height/orientation/bitsPerSample fallback |
| `src/Exif/Factory/GpsFactory.php` | Modify | Add QuickTime ©xyz ISO 6709 fallback |
| `src/Exif/Factory/TemporalFactory.php` | Modify | Add mvhd Mac-epoch timestamps as last fallback |
| `src/Core/Util/Iso6709Parser.php` | Create | Parse ISO 6709 location strings into lat/lon/alt |
| `tests/Exif/Factory/ValueFactoryTest.php` | Modify | Test Video rotation + bitDepth |
| `tests/Exif/Factory/CameraFactoryTest.php` | Modify | Test QuickTime make/model fallback |
| `tests/Exif/Factory/ImageFactoryTest.php` | Modify | Test QuickTime dimension/rotation fallback |
| `tests/Exif/Factory/GpsFactoryTest.php` | Modify | Test QuickTime ©xyz GPS fallback |
| `tests/Exif/Factory/TemporalFactoryTest.php` | Modify | Test mvhd timestamp fallback |
| `tests/Core/Util/Iso6709ParserTest.php` | Create | Test ISO 6709 parsing |

---

## Task 1: Add rotation and bitDepth to Video Value Object

**Files:**
- Modify: `src/Value/Video.php`
- Modify: `src/Exif/Factory/ValueFactory.php`
- Modify: `tests/Exif/Factory/ValueFactoryTest.php`

- [ ] **Step 1: Write failing test for Video rotation**

In `ValueFactoryTest.php`, add a test that builds a `Metadata` with `QuickTimeMeta` containing `ROTATION_KEY => 90` and `VIDEO_BIT_DEPTH_KEY => 24`. Assert the resulting `Video` object has `rotation === 90` and `bitDepth === 24`.

Look at existing tests in `ValueFactoryTest.php` to understand the pattern — they build `Metadata` with a `QuickTimeMeta` and call `createComponents()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL (Video has no rotation/bitDepth property)

- [ ] **Step 3: Add fields to Video value object**

In `src/Value/Video.php`, add two new constructor parameters after `colorPrimaries`:

```php
public function __construct(
    public ?float $durationSec = null,
    public ?float $frameRate = null,
    public ?int $width = null,
    public ?int $height = null,
    public ?string $codec = null,
    public bool $hdr = false,
    public ?string $transferFunction = null,
    public ?string $colorPrimaries = null,
    public ?int $rotation = null,
    public ?int $bitDepth = null,
) {
}
```

- [ ] **Step 4: Pass rotation and bitDepth in ValueFactory**

In `ValueFactory::createContainerMedia()`, where the `Video` object is constructed (~line 294), add:

```php
$video = new Video(
    // ... existing fields ...
    colorPrimaries: $quickTimeLookup->string('com.apple.quicktime.colorPrimaries'),
    rotation: $quickTimeLookup->int(QuickTimeMeta::ROTATION_KEY),
    bitDepth: $quickTimeLookup->int(QuickTimeMeta::VIDEO_BIT_DEPTH_KEY),
);
```

- [ ] **Step 5: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 6: Commit**

```
GH-0: Add rotation and bitDepth to Video value object
```

---

## Task 2: QuickTime Make/Model Fallback in CameraFactory

**Files:**
- Modify: `src/Exif/Factory/CameraFactory.php`
- Modify: `tests/Exif/Factory/CameraFactoryTest.php`

- [ ] **Step 1: Write failing test for QuickTime make/model fallback**

Add a test `fallsBackToQuickTimeMakeModel()` that creates a `Metadata` with `exifDoc: null` and `quickTime: new QuickTimeMeta(['com.apple.quicktime.make' => 'Apple', 'com.apple.quicktime.model' => 'iPhone 16 Pro'])`. Assert `Camera.make === 'Apple'` and `Camera.model === 'iPhone 16 Pro'`.

Also add a test `exifTakesPrecedenceOverQuickTimeMakeModel()` that provides both EXIF make/model AND QuickTime make/model, and asserts the EXIF values win.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL (make/model are null because QT is not consulted)

- [ ] **Step 3: Add QuickTime fallback to CameraFactory**

In `CameraFactory::create()`, add a `QuickTimeLookup` and extend the fallback chain:

```php
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;

// After existing EXIF/XMP resolution:
$quickTimeLookup = new QuickTimeLookup($metadata->quickTime);

return new Camera(
    make: $exifDocument?->cameraMake() ?? $resolver?->string(ExifTag::MAKE) ?? $quickTimeLookup->string('Make'),
    model: $exifDocument?->cameraModel() ?? $resolver?->string(ExifTag::MODEL) ?? $quickTimeLookup->string('Model'),
    // ... rest unchanged ...
);
```

The `QuickTimeLookup->string('Make')` will resolve via KEY_ALIASES: `'Make'` is not a direct alias but `'com.apple.quicktime.make'` is the canonical key stored by the `©mak` udta atom. Use the canonical key directly:

```php
make: ... ?? $quickTimeLookup->string('com.apple.quicktime.make'),
model: ... ?? $quickTimeLookup->string('com.apple.quicktime.model'),
```

- [ ] **Step 4: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 5: Commit**

```
GH-0: Add QuickTime make/model fallback to CameraFactory
```

---

## Task 3: QuickTime Dimension/Orientation Fallback in ImageFactory

**Files:**
- Modify: `src/Exif/Factory/ImageFactory.php`
- Modify: `tests/Exif/Factory/ImageFactoryTest.php`

- [ ] **Step 1: Write failing test for QuickTime dimension fallback**

Add `fallsBackToQuickTimeDimensions()`: Metadata with no EXIF, no JPEG frame data, but QuickTimeMeta with `VIDEO_WIDTH_KEY => 3840`, `VIDEO_HEIGHT_KEY => 2160`, `VIDEO_BIT_DEPTH_KEY => 24`. Assert `Image.width === 3840`, `Image.height === 2160`, `Image.bitsPerSample === 24`.

Add `fallsBackToQuickTimeRotationAsOrientation()`: QuickTimeMeta with `ROTATION_KEY => 90`. Assert `Image.orientation === Orientation::RightTop` (90° = EXIF orientation 6).

Add `exifDimensionsTakePrecedenceOverQuickTime()`: Both EXIF and QuickTime dimensions present, EXIF wins.

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Add QuickTime fallback to ImageFactory**

Add imports to `ImageFactory.php`:
```php
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
```

**IMPORTANT:** Do NOT remove existing XMP-related code (title, description, comment). Only extend the fallback chains for width/height/orientation/bitsPerSample.

In `ImageFactory::create()`:

```php
$quickTimeLookup = new QuickTimeLookup($metadata->quickTime);

return new Image(
    width: $exifDocument?->imageWidth() ?? $metadata->jpegFrameWidth ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_WIDTH_KEY),
    height: $exifDocument?->imageHeight() ?? $metadata->jpegFrameHeight ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_HEIGHT_KEY),
    orientation: $exifDocument?->orientation() ?? $this->rotationToOrientation($quickTimeLookup->int(QuickTimeMeta::ROTATION_KEY)),
    bitsPerSample: $exifDocument?->bitsPerSample() ?? $metadata->jpegBitsPerSample ?? $quickTimeLookup->int(QuickTimeMeta::VIDEO_BIT_DEPTH_KEY),
    // ... rest unchanged ...
);
```

Add helper to map rotation degrees to EXIF Orientation enum:

```php
/**
 * Maps QuickTime rotation degrees to EXIF Orientation.
 *
 * QuickTime stores rotation as degrees (0/90/180/270) in the tkhd matrix.
 * EXIF uses an 8-value orientation enum. Only the four cardinal rotations
 * have a direct mapping.
 */
private function rotationToOrientation(?int $rotation): ?Orientation
{
    return match ($rotation) {
        0       => Orientation::TopLeft,
        90      => Orientation::RightTop,
        180     => Orientation::BottomRight,
        270     => Orientation::LeftBottom,
        default => null,
    };
}
```

- [ ] **Step 4: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 5: Commit**

```
GH-0: Add QuickTime dimension/orientation fallback to ImageFactory
```

---

## Task 4: ISO 6709 Parser for ©xyz GPS Coordinates

**Files:**
- Create: `src/Core/Util/Iso6709Parser.php`
- Create: `tests/Core/Util/Iso6709ParserTest.php`

The `©xyz` atom stores GPS as ISO 6709 strings like `+48.1234+011.5678+500.000/`. This must be parsed into separate latitude, longitude, altitude values before GpsFactory can use it.

- [ ] **Step 1: Write failing tests for ISO 6709 parsing**

Use `assertEqualsWithDelta` for float comparisons (floating-point representation safety):

```php
#[Test]
public function parsesLatLonAltWithTrailingSlash(): void
{
    $result = Iso6709Parser::parse('+48.1234+011.5678+500.000/');

    self::assertNotNull($result);
    self::assertEqualsWithDelta(48.1234, $result['latitude'], 0.0001);
    self::assertEqualsWithDelta(11.5678, $result['longitude'], 0.0001);
    self::assertEqualsWithDelta(500.0, $result['altitude'], 0.1);
}

#[Test]
public function parsesNegativeCoordinates(): void
{
    $result = Iso6709Parser::parse('-33.8688+151.2093/');

    self::assertNotNull($result);
    self::assertEqualsWithDelta(-33.8688, $result['latitude'], 0.0001);
    self::assertEqualsWithDelta(151.2093, $result['longitude'], 0.0001);
    self::assertNull($result['altitude']);
}

#[Test]
public function parsesLatLonWithoutAltitude(): void
{
    $result = Iso6709Parser::parse('+34.0522-118.2437/');

    self::assertNotNull($result);
    self::assertEqualsWithDelta(34.0522, $result['latitude'], 0.0001);
    self::assertEqualsWithDelta(-118.2437, $result['longitude'], 0.0001);
    self::assertNull($result['altitude']);
}

#[Test]
public function returnsNullForInvalidInput(): void
{
    self::assertNull(Iso6709Parser::parse(''));
    self::assertNull(Iso6709Parser::parse('invalid'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Implement Iso6709Parser**

```php
<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use function preg_match;

/**
 * Parses ISO 6709 location strings as used in QuickTime ©xyz atoms.
 *
 * Format: ±DD.DDDD±DDD.DDDD[±DDD.DDD][/]
 * - Latitude: signed decimal degrees (2-3 integer digits)
 * - Longitude: signed decimal degrees (2-3 integer digits)
 * - Altitude: optional signed decimal meters
 * - Trailing slash: optional terminator
 */
final class Iso6709Parser
{
    /**
     * Parses an ISO 6709 coordinate string into latitude, longitude, and optional altitude.
     *
     * @return array{latitude: float, longitude: float, altitude: float|null}|null
     */
    public static function parse(string $input): ?array
    {
        // Match: ±lat±lon[±alt][/]
        $pattern = '/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)?\/?\s*$/';

        if (preg_match($pattern, $input, $matches) !== 1) {
            return null;
        }

        return [
            'latitude'  => (float) $matches[1],
            'longitude' => (float) $matches[2],
            'altitude'  => isset($matches[3]) && ($matches[3] !== '') ? (float) $matches[3] : null,
        ];
    }
}
```

- [ ] **Step 4: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 5: Commit**

```
GH-0: Add ISO 6709 location string parser
```

---

## Task 5: QuickTime GPS Fallback in GpsFactory

**Files:**
- Modify: `src/Exif/Factory/GpsFactory.php`
- Modify: `tests/Exif/Factory/GpsFactoryTest.php`

- [ ] **Step 1: Write failing test for QuickTime GPS fallback**

Add `fallsBackToQuickTimeGpsFromXyz()`: Metadata with no EXIF GPS, but QuickTimeMeta with `'com.apple.quicktime.location.ISO6709' => '+48.1234+011.5678+500.000/'`. Assert `Gps.position.latitude` is approximately `48.1234`, `Gps.position.longitude` is approximately `11.5678`, `Gps.position.altitude` is approximately `500.0`.

Add `exifGpsTakesPrecedenceOverQuickTime()`: Both EXIF GPS and QuickTime ©xyz present, EXIF wins.

Also test DJI-style numeric GPS keys as fallback: QuickTimeMeta with `'com.apple.quicktime.location.latitude' => 48.1234`, `'com.apple.quicktime.location.longitude' => 11.5678`.

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Add QuickTime GPS fallback to GpsFactory**

**IMPORTANT architecture note:** The existing `GpsFactory::create()` calls `resolveGps()` which returns a `Gps` object (or null/empty). The QuickTime fallback must NOT be inserted inside `resolveGps()` — it operates at a different abstraction level. Instead, add the fallback in `create()` AFTER the existing EXIF/XMP-based `Gps` is built. If the resulting `Gps` has no position, try QuickTime.

Read the current `GpsFactory::create()` and `GpsPosition` constructor before implementing. The `GpsPosition` constructor takes raw floats for lat/lon (unsigned magnitude + ref enum).

Add imports to `GpsFactory.php`:
```php
use MagicSunday\ImageMeta\Core\Util\Iso6709Parser;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use function abs;
```

In `GpsFactory::create()`, after the existing `Gps` object is built, add:

```php
// QuickTime GPS fallback when EXIF/XMP produced no position
if ($gps->position === null) {
    $quickTimeLookup = new QuickTimeLookup($metadata->quickTime);
    $qtPosition      = $this->resolveQuickTimeGps($quickTimeLookup);

    if ($qtPosition !== null) {
        $gps = new Gps(position: $qtPosition);
    }
}

return $gps;
```

Add private helper:

```php
/**
 * Resolves GPS position from QuickTime metadata sources.
 *
 * Tries ISO 6709 location string first (©xyz atom), then falls back
 * to separate numeric latitude/longitude keys (DJI telemetry).
 */
private function resolveQuickTimeGps(QuickTimeLookup $lookup): ?GpsPosition
{
    // Try ISO 6709 from ©xyz atom
    $iso6709 = $lookup->string('com.apple.quicktime.location.ISO6709');

    if ($iso6709 !== null) {
        $parsed = Iso6709Parser::parse($iso6709);

        if ($parsed !== null) {
            return new GpsPosition(
                latitude: abs($parsed['latitude']),
                latitudeRef: $parsed['latitude'] >= 0 ? GpsLatLonRef::North : GpsLatLonRef::South,
                longitude: abs($parsed['longitude']),
                longitudeRef: $parsed['longitude'] >= 0 ? GpsLatLonRef::East : GpsLatLonRef::West,
                altitude: $parsed['altitude'] !== null ? abs($parsed['altitude']) : null,
                altitudeRef: $parsed['altitude'] !== null
                    ? ($parsed['altitude'] >= 0 ? GpsAltitudeRef::AboveEllipsoidalSurface : GpsAltitudeRef::BelowEllipsoidalSurface)
                    : null,
            );
        }
    }

    // Try separate numeric keys (DJI telemetry)
    $lat = $lookup->float('com.apple.quicktime.location.latitude');
    $lon = $lookup->float('com.apple.quicktime.location.longitude');

    if (($lat !== null) && ($lon !== null)) {
        $alt = $lookup->float('com.apple.quicktime.location.altitude');

        return new GpsPosition(
            latitude: abs($lat),
            latitudeRef: $lat >= 0 ? GpsLatLonRef::North : GpsLatLonRef::South,
            longitude: abs($lon),
            longitudeRef: $lon >= 0 ? GpsLatLonRef::East : GpsLatLonRef::West,
            altitude: $alt !== null ? abs($alt) : null,
            altitudeRef: $alt !== null
                ? ($alt >= 0 ? GpsAltitudeRef::AboveEllipsoidalSurface : GpsAltitudeRef::BelowEllipsoidalSurface)
                : null,
        );
    }

    return null;
}
```

**Note:** Read the actual `GpsPosition` constructor and `Gps` constructor before implementing. The `Gps` constructor has many optional parameters (destination, movement, timing, measurement) — all default to null. When constructing from QuickTime, only `position` is populated.

- [ ] **Step 4: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 5: Commit**

```
GH-0: Add QuickTime GPS fallback from ©xyz and DJI telemetry
```

---

## Task 6: mvhd Mac-Epoch Timestamp Fallback in TemporalFactory

**Files:**
- Modify: `src/Exif/Factory/TemporalFactory.php`
- Modify: `tests/Exif/Factory/TemporalFactoryTest.php`

TemporalFactory already uses QuickTime string-based `CreationDate`/`ModifyDate` keys. The new mvhd Mac-epoch timestamps (`CREATE_DATE_KEY`, `MODIFY_DATE_KEY`) should serve as last-resort fallback when even those string keys are absent.

- [ ] **Step 1: Write failing test for mvhd timestamp fallback**

Add `fallsBackToMvhdMacEpochTimestamps()`: Metadata with no EXIF, no XMP, QuickTimeMeta with only `CREATE_DATE_KEY => 3_692_217_600` (= 2021-01-01 UTC as Mac epoch) and `MODIFY_DATE_KEY => 3_692_304_000`. Assert `Temporal.create` is a `DateTimeImmutable` for 2021-01-01 and `Temporal.modify` is set.

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Add mvhd timestamp fallback**

In `TemporalFactory::buildTemporal()`, after the existing QuickTime string-based fallback, add a Mac epoch fallback:

```php
// Existing chain:
$create = $exifCreate ?? $xmpCreate ?? $quickTimeCreate ?? $xmpDateCreated;
$modify = $exifModify ?? $xmpModify ?? $quickTimeModify;

// New: mvhd Mac-epoch as last resort
if ($create === null) {
    $create = $this->macEpochToDateTime($lookup->int(QuickTimeMeta::CREATE_DATE_KEY));
}

if ($modify === null) {
    $modify = $this->macEpochToDateTime($lookup->int(QuickTimeMeta::MODIFY_DATE_KEY));
}
```

Add helper:

```php
/**
 * Converts a Mac epoch timestamp (seconds since 1904-01-01) to DateTimeImmutable.
 *
 * Returns null for zero or null timestamps (uninitialised mvhd fields).
 */
private function macEpochToDateTime(?int $macTimestamp): ?DateTimeImmutable
{
    if (($macTimestamp === null) || ($macTimestamp === 0)) {
        return null;
    }

    $unixTimestamp = $macTimestamp - 2_082_844_800;

    return new DateTimeImmutable('@' . $unixTimestamp);
}
```

- [ ] **Step 4: Run tests**

Run: `make unit`
Expected: PASS

- [ ] **Step 5: Commit**

```
GH-0: Add mvhd Mac-epoch timestamp fallback to TemporalFactory
```

---

## Task 7: Final CI and Cleanup

- [ ] **Step 1: Run full CI**

Run: `make test`
Expected: ALL PASS (phplint, cgl, rector, phpstan, phpunit, jscpd)

- [ ] **Step 2: Fix any issues**

Run `make cgl` and `make rector` if needed.

- [ ] **Step 3: Run CI again**

Run: `make test`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```
GH-0: Fix CI issues from StructuredMetadata QuickTime fallbacks
```

---

## Dependency Graph

```
Task 1 (Video rotation/bitDepth)
Task 2 (Camera make/model)
Task 3 (Image dimensions/orientation)
Task 4 (ISO 6709 parser) → Task 5 (GPS fallback)
Task 6 (Temporal mvhd fallback)
All above → Task 7 (CI cleanup)
```

Tasks 1-4 and 6 are independent. Task 5 depends on Task 4.

---

## Out of Scope

These QuickTime fields are intentionally NOT added to StructuredMetadata:
- TimeScale, NextTrackID, PreviewTime/Duration, PosterTime, SelectionTime/Duration, CurrentTime (container internals)
- MatrixStructure, TrackMatrixStructure (rotation covers the user need)
- GraphicsMode, OpColor, Balance (rendering details)
- TrackLayer, TrackVolume (container management)
- LOOP, SelO, AllF, WLOC (player controls)
- MediaLanguageCode (container-level, not content-level)

These remain accessible via `$metadata->quickTime->intValue(...)` for specialized use cases.
