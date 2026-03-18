# QuickTime Missing Metadata Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract all QuickTime metadata fields that the spec defines and ExifTool exposes but this library currently discards or ignores.

**Architecture:** Each group of metadata (mvhd timestamps, tkhd rotation, mdhd language, video bit depth, udta text atoms, vmhd/smhd fields) gets its own task. New constants are added to `QuickTimeMeta`, parser methods are extended to return the data they already read, and the format script renders the new fields. Every change follows TDD: failing test first, then implementation.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan max level, synthetic binary test data via `box()`/`fullBox()` helpers from `IsoBmffBoxTrait`.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `src/Model/QuickTime/QuickTimeMeta.php` | Modify | Add ~30 new key constants + KEY_ALIASES entries |
| `src/Parse/IsoBmff/TrackMediaParser.php` | Modify | Expose mvhd/tkhd/mdhd fields; extract vmhd/smhd; pass depth |
| `src/Parse/IsoBmff/VideoSampleEntryParser.php` | Modify | Return `depth` in result array |
| `src/Parse/IsoBmff/QuickTimeMetadataDecoder.php` | Modify | Add ~17 missing udta text atoms to `UDTA_TEXT_KEYS` |
| `src/Parse/IsoBmff/IsoBmffParser.php` | Modify | Thread mvhd/tkhd/mdhd metadata into `context->qtKeys` |
| `scripts/imagemeta-format.php` | Modify | Render new QuickTime fields (timestamps, rotation, duration, etc.) |
| `tests/Parse/IsoBmff/TrackMediaParserTest.php` | Modify | Tests for mvhd/tkhd/mdhd metadata extraction |
| `tests/Parse/IsoBmff/VideoSampleEntryParserTest.php` | Modify | Test depth field in result |
| `tests/Parse/IsoBmff/QuickTimeMetadataDecoderTest.php` | Modify | Tests for new udta text atoms |

---

## Task 1: Add QuickTimeMeta Key Constants

All new metadata needs named constants so keys are centralized and aliased.

**Files:**
- Modify: `src/Model/QuickTime/QuickTimeMeta.php`

- [ ] **Step 1: Add mvhd key constants**

```php
// After COMPATIBLE_BRANDS_KEY constant block (~line 48):

/**
 * QuickTime metadata key exposing the movie creation timestamp (Mac epoch).
 */
public const string CREATE_DATE_KEY = 'com.apple.quicktime.creationDate.mvhd';

/**
 * QuickTime metadata key exposing the movie modification timestamp (Mac epoch).
 */
public const string MODIFY_DATE_KEY = 'com.apple.quicktime.modificationDate.mvhd';

/**
 * QuickTime metadata key exposing the movie duration in seconds.
 */
public const string DURATION_KEY = 'com.apple.quicktime.duration';

/**
 * QuickTime metadata key exposing the movie timescale (time units per second).
 */
public const string TIME_SCALE_KEY = 'com.apple.quicktime.timeScale';

/**
 * QuickTime metadata key exposing the preferred playback rate (1.0 = normal).
 */
public const string PREFERRED_RATE_KEY = 'com.apple.quicktime.preferredRate';

/**
 * QuickTime metadata key exposing the preferred playback volume (1.0 = full).
 */
public const string PREFERRED_VOLUME_KEY = 'com.apple.quicktime.preferredVolume';

/**
 * QuickTime metadata key exposing the movie-level matrix structure.
 */
public const string MATRIX_STRUCTURE_KEY = 'com.apple.quicktime.matrixStructure';

/**
 * QuickTime metadata key exposing the next available track ID.
 */
public const string NEXT_TRACK_ID_KEY = 'com.apple.quicktime.nextTrackID';

/**
 * QuickTime metadata key exposing preview time in movie timescale.
 */
public const string PREVIEW_TIME_KEY = 'com.apple.quicktime.previewTime';

/**
 * QuickTime metadata key exposing preview duration in movie timescale.
 */
public const string PREVIEW_DURATION_KEY = 'com.apple.quicktime.previewDuration';

/**
 * QuickTime metadata key exposing poster time in movie timescale.
 */
public const string POSTER_TIME_KEY = 'com.apple.quicktime.posterTime';

/**
 * QuickTime metadata key exposing selection start time in movie timescale.
 */
public const string SELECTION_TIME_KEY = 'com.apple.quicktime.selectionTime';

/**
 * QuickTime metadata key exposing selection duration in movie timescale.
 */
public const string SELECTION_DURATION_KEY = 'com.apple.quicktime.selectionDuration';

/**
 * QuickTime metadata key exposing current time position in movie timescale.
 */
public const string CURRENT_TIME_KEY = 'com.apple.quicktime.currentTime';
```

- [ ] **Step 2: Add tkhd key constants**

```php
/**
 * QuickTime metadata key exposing track creation timestamp (Mac epoch).
 */
public const string TRACK_CREATE_DATE_KEY = 'com.apple.quicktime.trackCreationDate';

/**
 * QuickTime metadata key exposing track modification timestamp (Mac epoch).
 */
public const string TRACK_MODIFY_DATE_KEY = 'com.apple.quicktime.trackModificationDate';

/**
 * QuickTime metadata key exposing the track ID.
 */
public const string TRACK_ID_KEY = 'com.apple.quicktime.trackID';

/**
 * QuickTime metadata key exposing track duration in movie timescale.
 */
public const string TRACK_DURATION_KEY = 'com.apple.quicktime.trackDuration';

/**
 * QuickTime metadata key exposing track spatial layer ordering.
 */
public const string TRACK_LAYER_KEY = 'com.apple.quicktime.trackLayer';

/**
 * QuickTime metadata key exposing track volume (8.8 fixed-point, 1.0 = full).
 */
public const string TRACK_VOLUME_KEY = 'com.apple.quicktime.trackVolume';

/**
 * QuickTime metadata key exposing rotation degrees derived from the tkhd matrix.
 */
public const string ROTATION_KEY = 'com.apple.quicktime.rotation';

/**
 * QuickTime metadata key exposing the track-level matrix structure.
 */
public const string TRACK_MATRIX_KEY = 'com.apple.quicktime.trackMatrixStructure';
```

- [ ] **Step 3: Add mdhd key constants**

```php
/**
 * QuickTime metadata key exposing media creation timestamp (Mac epoch).
 */
public const string MEDIA_CREATE_DATE_KEY = 'com.apple.quicktime.mediaCreationDate';

/**
 * QuickTime metadata key exposing media modification timestamp (Mac epoch).
 */
public const string MEDIA_MODIFY_DATE_KEY = 'com.apple.quicktime.mediaModificationDate';

/**
 * QuickTime metadata key exposing media duration in media timescale.
 */
public const string MEDIA_DURATION_KEY = 'com.apple.quicktime.mediaDuration';

/**
 * QuickTime metadata key exposing media timescale (time units per second).
 */
public const string MEDIA_TIME_SCALE_KEY = 'com.apple.quicktime.mediaTimeScale';

/**
 * QuickTime metadata key exposing the packed ISO 639-2/T media language code.
 */
public const string MEDIA_LANGUAGE_CODE_KEY = 'com.apple.quicktime.mediaLanguageCode';
```

- [ ] **Step 4: Add video/audio info key constants**

```php
/**
 * QuickTime metadata key exposing video sample entry bit depth.
 */
public const string VIDEO_BIT_DEPTH_KEY = 'com.apple.quicktime.videoBitDepth';

/**
 * QuickTime metadata key exposing video media graphics mode from vmhd.
 */
public const string GRAPHICS_MODE_KEY = 'com.apple.quicktime.graphicsMode';

/**
 * QuickTime metadata key exposing video media operation color from vmhd.
 */
public const string OP_COLOR_KEY = 'com.apple.quicktime.opColor';

/**
 * QuickTime metadata key exposing audio balance from smhd.
 */
public const string BALANCE_KEY = 'com.apple.quicktime.balance';
```

- [ ] **Step 5: Add KEY_ALIASES entries for all new constants**

Add bidirectional alias entries for every new constant in the `KEY_ALIASES` array (same pattern as existing entries). Key aliases to add (both directions for each):

**IMPORTANT:** The `'Duration'` alias already exists in the current KEY_ALIASES map. Do NOT add a duplicate — update the existing entry to reference `self::DURATION_KEY`:
```php
// Update existing entry:
self::DURATION_KEY => [self::DURATION_KEY, 'Duration', 'com.apple.quicktime.duration'],
'Duration'         => ['Duration', self::DURATION_KEY, 'com.apple.quicktime.duration'],
```

- `CREATE_DATE_KEY` <-> `'CreateDate'`
- `MODIFY_DATE_KEY` <-> `'ModifyDate'`
- `TIME_SCALE_KEY` <-> `'TimeScale'`
- `PREFERRED_RATE_KEY` <-> `'PreferredRate'`
- `PREFERRED_VOLUME_KEY` <-> `'PreferredVolume'`
- `MATRIX_STRUCTURE_KEY` <-> `'MatrixStructure'`
- `NEXT_TRACK_ID_KEY` <-> `'NextTrackID'`
- `PREVIEW_TIME_KEY` <-> `'PreviewTime'`
- `PREVIEW_DURATION_KEY` <-> `'PreviewDuration'`
- `POSTER_TIME_KEY` <-> `'PosterTime'`
- `SELECTION_TIME_KEY` <-> `'SelectionTime'`
- `SELECTION_DURATION_KEY` <-> `'SelectionDuration'`
- `CURRENT_TIME_KEY` <-> `'CurrentTime'`
- `TRACK_CREATE_DATE_KEY` <-> `'TrackCreateDate'`
- `TRACK_MODIFY_DATE_KEY` <-> `'TrackModifyDate'`
- `TRACK_ID_KEY` <-> `'TrackID'`
- `TRACK_DURATION_KEY` <-> `'TrackDuration'`
- `TRACK_LAYER_KEY` <-> `'TrackLayer'`
- `TRACK_VOLUME_KEY` <-> `'TrackVolume'`
- `ROTATION_KEY` <-> `'Rotation'`
- `TRACK_MATRIX_KEY` <-> `'TrackMatrixStructure'`
- `MEDIA_CREATE_DATE_KEY` <-> `'MediaCreateDate'`
- `MEDIA_MODIFY_DATE_KEY` <-> `'MediaModifyDate'`
- `MEDIA_DURATION_KEY` <-> `'MediaDuration'`
- `MEDIA_TIME_SCALE_KEY` <-> `'MediaTimeScale'`
- `MEDIA_LANGUAGE_CODE_KEY` <-> `'MediaLanguageCode'`
- `VIDEO_BIT_DEPTH_KEY` <-> `'BitDepth'`, `'VideoBitDepth'`
- `GRAPHICS_MODE_KEY` <-> `'GraphicsMode'`
- `OP_COLOR_KEY` <-> `'OpColor'`
- `BALANCE_KEY` <-> `'Balance'`

- [ ] **Step 6: Run PHPStan to verify no type errors**

Run: `make stan`
Expected: PASS (no errors)

- [ ] **Step 7: Commit**

```
GH-XXX: Add QuickTimeMeta key constants for missing metadata fields
```

---

## Task 2: Extract mvhd Metadata

Currently `parseMvhd()` validates and discards creation/modification timestamps, duration, rate, volume, matrix, and next-track-ID. Change it to return these values and thread them into `context->qtKeys`.

**Files:**
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:267-288`
- Modify: `src/Parse/IsoBmff/IsoBmffParser.php:250-258` (parseMoovBox mvhd handling)
- Modify: `tests/Parse/IsoBmff/TrackMediaParserTest.php`

**Spec reference:** QuickTime File Format 2012, "Movie Header Atom"; ISO/IEC 14496-12 §8.2.2.

**Byte layout after version/flags/creation/modification/timescale (already parsed by `parseTimescaleHeader`):**

Version 0: duration(4), rate(4), volume(2), reserved(10), matrix(36), previewTime(4), previewDuration(4), posterTime(4), selectionTime(4), selectionDuration(4), currentTime(4), nextTrackID(4)

Version 1: duration(8), rate(4), volume(2), reserved(10), matrix(36), previewTime(4), previewDuration(4), posterTime(4), selectionTime(4), selectionDuration(4), currentTime(4), nextTrackID(4)

**NOTE:** The six fields (previewTime through currentTime) are QuickTime-specific. In pure ISO BMFF (non-QuickTime), these 24 bytes correspond to the `pre_defined` field and may contain arbitrary values. Add a code comment noting this overlap.

**Timestamp epoch:** Seconds since 1904-01-01 00:00:00 UTC. To convert to Unix: subtract 2_082_844_800.

- [ ] **Step 1: Write failing test for mvhd metadata extraction (version 0)**

In `TrackMediaParserTest.php`, add a test that builds a valid mvhd v0 box with known creation/modification timestamps, duration, rate, volume, matrix, and nextTrackID, parses it, and asserts the returned array contains all fields.

The test must build a complete 108-byte mvhd box (8 header + 100 content):
- version=0, flags=0
- creation_time=3_692_217_600 (2021-01-01 UTC as Mac epoch)
- modification_time=3_692_304_000
- timescale=600
- duration=3600 (= 6 seconds at timescale 600)
- rate=0x00010000 (1.0 as 16.16 fixed)
- volume=0x0100 (1.0 as 8.8 fixed)
- reserved=10 zero bytes
- matrix=identity (a=0x00010000, b=0, u=0, c=0, d=0x00010000, v=0, x=0, y=0, w=0x40000000)
- previewTime=0, previewDuration=0, posterTime=0, selectionTime=0, selectionDuration=0, currentTime=0
- nextTrackID=2

Assert the returned array keys match expected values:
- `'createDate'` => 3_692_217_600
- `'modifyDate'` => 3_692_304_000
- `'duration'` => 3600
- `'timescale'` => 600
- `'preferredRate'` => 1.0
- `'preferredVolume'` => 1.0
- `'matrix'` => identity matrix string representation
- `'nextTrackID'` => 2
- `'previewTime'` => 0
- `'previewDuration'` => 0
- `'posterTime'` => 0
- `'selectionTime'` => 0
- `'selectionDuration'` => 0
- `'currentTime'` => 0

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL (parseMvhd returns void, test expects array)

- [ ] **Step 3: Modify `parseTimescaleHeader()` to return timestamps and timescale**

Change the return type of `parseTimescaleHeader()` from `int` (version only) to a structured array:

```php
/**
 * @return array{version: int, createDate: int, modifyDate: int, timescale: int}
 */
private function parseTimescaleHeader(...): array
```

Instead of skipping creation/modification timestamps with `$win->read(8+8)`, read them:

```php
if ($version === 1) {
    $createDate = $win->readU64BE()->toInt('creation_time');
    $modifyDate = $win->readU64BE()->toInt('modification_time');
} else {
    $createDate = $win->readU32BE();
    $modifyDate = $win->readU32BE();
}

$timescale = $win->readU32BE();
// ... validation ...

return [
    'version'    => $version,
    'createDate' => $createDate,
    'modifyDate' => $modifyDate,
    'timescale'  => $timescale,
];
```

**IMPORTANT:** `StreamWindow::readU64BE()` returns a `UInt64` value object (defined in `Core/Util/UInt64.php`). Call `->toInt($context)` to convert to a platform integer with overflow protection. Do NOT implement a custom `readU64BE()` helper — use the existing infrastructure.

- [ ] **Step 4: Modify `parseMvhd()` to return all fields**

Change signature from `public function parseMvhd(BoxDescriptor $mvhd): void` to:

```php
/**
 * @return array<string, int|float|string>
 */
public function parseMvhd(BoxDescriptor $mvhd): array
```

After `parseTimescaleHeader()` returns, read remaining fields:

```php
$header = $this->parseTimescaleHeader($mvhd, 100, 112, ...);
$win    = $mvhd->window;

// Duration
$duration = ($header['version'] === 1) ? $win->readU64BE()->toInt('duration') : $win->readU32BE();

// Preferred rate: 16.16 fixed-point
$rateRaw = $win->readU32BE();
$rate    = $rateRaw / 65536.0;

// Preferred volume: 8.8 fixed-point
$volumeRaw = $win->readU16BE();
$volume    = $volumeRaw / 256.0;

// Reserved (10 bytes)
$win->read(10);

// Matrix (36 bytes) — read as formatted string
$matrix = $this->readMatrixStructure($win);

// Preview time, preview duration, poster time, selection time,
// selection duration, current time (each 4 bytes)
$previewTime       = $win->readU32BE();
$previewDuration   = $win->readU32BE();
$posterTime        = $win->readU32BE();
$selectionTime     = $win->readU32BE();
$selectionDuration = $win->readU32BE();
$currentTime       = $win->readU32BE();

$nextTrackId = $win->readU32BE();

if ($nextTrackId === 0) {
    throw new ParseError('mvhd next_track_ID must not be zero', 1409);
}

return [
    'createDate'        => $header['createDate'],
    'modifyDate'        => $header['modifyDate'],
    'timescale'         => $header['timescale'],
    'duration'          => $duration,
    'preferredRate'     => $rate,
    'preferredVolume'   => $volume,
    'matrix'            => $matrix,
    'previewTime'       => $previewTime,
    'previewDuration'   => $previewDuration,
    'posterTime'        => $posterTime,
    'selectionTime'     => $selectionTime,
    'selectionDuration' => $selectionDuration,
    'currentTime'       => $currentTime,
    'nextTrackID'       => $nextTrackId,
];
```

- [ ] **Step 5: Add `readMatrixRaw()` and `formatMatrix()` helpers**

The 3x3 matrix is 9 values: positions 0,1,3,4,6,7 (a,b,c,d,x,y) are signed 16.16 fixed-point; positions 2,5,8 (u,v,w) are 2.30 fixed-point. ExifTool formats as space-separated decimal values.

Define two helpers — a raw reader and a formatter — so Task 3 (tkhd matrix) can reuse the formatter:

```php
/**
 * Reads nine raw 32-bit matrix values from the stream.
 *
 * @return list<int>
 */
private function readMatrixRaw(StreamWindow $win): array
{
    $raw = [];

    for ($i = 0; $i < 9; ++$i) {
        $raw[] = $win->readU32BE();
    }

    return $raw;
}

/**
 * Formats a raw 9-element QuickTime transformation matrix into a
 * space-separated string of decimal values.
 *
 * QuickTime File Format 2012, "Matrix Structure": rows {a, b, u},
 * {c, d, v}, {x, y, w}. Positions 0,1,3,4,6,7 are signed 16.16
 * fixed-point; positions 2,5,8 are 2.30 fixed-point.
 *
 * @param list<int> $matrixRaw Nine raw 32-bit values.
 */
private function formatMatrix(array $matrixRaw): string
{
    $values = [];

    for ($i = 0; $i < 9; ++$i) {
        $raw      = $matrixRaw[$i];
        $values[] = (($i % 3) === 2)
            ? $raw / 1_073_741_824.0  // 2^30
            : $this->decodeSigned16_16($raw);
    }

    return implode(' ', array_map(
        static fn (float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'),
        $values,
    ));
}
```

Use in `parseMvhd()`:
```php
$matrixRaw = $this->readMatrixRaw($win);
$matrix    = $this->formatMatrix($matrixRaw);
```

- [ ] **Step 6: Add `decodeSigned16_16()` helper for signed 16.16 fixed-point**

```php
/**
 * Decodes a 32-bit signed 16.16 fixed-point value.
 */
private function decodeSigned16_16(int $raw): float
{
    // Interpret as signed 32-bit
    if ($raw >= 0x80000000) {
        $raw -= 0x100000000;
    }

    return $raw / 65536.0;
}
```

- [ ] **Step 7: Add `use` imports for new functions/constants**

Add required `use function` and `use const` imports to `TrackMediaParser.php`:

```php
use function atan2;
use function array_map;
use function implode;
use function number_format;
use function chr;
use const M_PI;
```

Also add `use MagicSunday\ImageMeta\Core\Util\UInt64;` if not already present (it's needed for the `readU64BE()` return type used in `parseTimescaleHeader`).

- [ ] **Step 8: Update `parseMdhd()` callers to use new return shape from `parseTimescaleHeader()`**

`parseMdhd()` currently calls `parseTimescaleHeader()` and discards the return. Update it immediately to accept the new array return type (the actual mdhd field extraction happens in Task 4, but this step prevents PHPStan from flagging an unused return value):

```php
/**
 * @return array{version: int, createDate: int, modifyDate: int, timescale: int}
 */
private function parseMdhd(BoxDescriptor $mdhd): array
{
    return $this->parseTimescaleHeader($mdhd, 24, 36, 1901, 1903, 1904, 1902, 1905);
}
```

Update the caller in `parseMdia()` (line 564) from:
```php
$this->parseMdhd($child);
```
to:
```php
$mdhdHeader = $this->parseMdhd($child);
```

Task 4 will then extend `parseMdhd()` to read duration and language from the remaining bytes.

- [ ] **Step 9: Update `parseMoovBox()` in `IsoBmffParser.php` to store mvhd metadata**

In `IsoBmffParser.php:250-258`, change from:
```php
$this->trackMediaParser->parseMvhd($child);
```
to:
```php
$mvhdData = $this->trackMediaParser->parseMvhd($child);
$this->mergeMvhdKeys($context, $mvhdData);
```

Add a private method:
```php
/**
 * Merges movie header metadata into the parse context.
 *
 * @param IsoBmffParseContext          $context
 * @param array<string, int|float|string> $mvhdData
 */
private function mergeMvhdKeys(IsoBmffParseContext $context, array $mvhdData): void
{
    $mapping = [
        'createDate'        => QuickTimeMeta::CREATE_DATE_KEY,
        'modifyDate'        => QuickTimeMeta::MODIFY_DATE_KEY,
        'duration'          => QuickTimeMeta::DURATION_KEY,
        'timescale'         => QuickTimeMeta::TIME_SCALE_KEY,
        'preferredRate'     => QuickTimeMeta::PREFERRED_RATE_KEY,
        'preferredVolume'   => QuickTimeMeta::PREFERRED_VOLUME_KEY,
        'matrix'            => QuickTimeMeta::MATRIX_STRUCTURE_KEY,
        'nextTrackID'       => QuickTimeMeta::NEXT_TRACK_ID_KEY,
        'previewTime'       => QuickTimeMeta::PREVIEW_TIME_KEY,
        'previewDuration'   => QuickTimeMeta::PREVIEW_DURATION_KEY,
        'posterTime'        => QuickTimeMeta::POSTER_TIME_KEY,
        'selectionTime'     => QuickTimeMeta::SELECTION_TIME_KEY,
        'selectionDuration' => QuickTimeMeta::SELECTION_DURATION_KEY,
        'currentTime'       => QuickTimeMeta::CURRENT_TIME_KEY,
    ];

    foreach ($mapping as $dataKey => $qtKey) {
        if (isset($mvhdData[$dataKey]) && !array_key_exists($qtKey, $context->qtKeys)) {
            $context->qtKeys[$qtKey] = $mvhdData[$dataKey];
        }
    }
}
```

- [ ] **Step 10: Write version 1 mvhd test (64-bit timestamps)**

Same as step 1 but with version=1 and 64-bit timestamps/duration fields.

- [ ] **Step 11: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 12: Commit**

```
GH-XXX: Extract mvhd metadata (timestamps, duration, rate, volume, matrix)
```

---

## Task 3: Extract tkhd Metadata (Rotation, Timestamps, Duration)

Currently `parseTkhd()` returns `[width, height, isEnabledInMovie]`. Extend it to also return creation/modification timestamps, track ID, duration, layer, volume, matrix, and computed rotation.

**Files:**
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:367-434`
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:76-147` (parseTrak)
- Modify: `tests/Parse/IsoBmff/TrackMediaParserTest.php`

**Spec reference:** QuickTime File Format 2012, "Track Header Atom"; ISO/IEC 14496-12 §8.3.2.

**Rotation derivation from matrix:**
The matrix values a, b, c, d encode rotation:
- 0deg:   a=1,  b=0,  c=0,  d=1
- 90deg:  a=0,  b=1,  c=-1, d=0
- 180deg: a=-1, b=0,  c=0,  d=-1
- 270deg: a=0,  b=-1, c=1,  d=0

Use `atan2(b, a)` to compute rotation in degrees. ExifTool normalizes to 0/90/180/270.

- [ ] **Step 1: Write failing test for tkhd rotation extraction**

Build a tkhd v0 box with the 90-degree rotation matrix:
- a=0x00000000, b=0x00010000, u=0, c=0xFFFF0000 (-1.0 as signed 16.16), d=0, v=0, x=0, y=0, w=0x40000000

Assert the returned result includes `'rotation' => 90`.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL

- [ ] **Step 3: Extend `parseTkhd()` return type**

Change return type from `array{0: ?int, 1: ?int, 2: bool}` to:

```php
/**
 * @return array{
 *     width: ?int,
 *     height: ?int,
 *     isEnabledInMovie: bool,
 *     createDate: int,
 *     modifyDate: int,
 *     trackId: int,
 *     duration: int,
 *     layer: int,
 *     volume: float,
 *     rotation: int,
 *     matrix: string,
 * }
 */
```

Instead of skipping timestamps/duration with `$win->read(...)`, read them:

```php
if ($version === 1) {
    $createDate = $win->readU64BE()->toInt('tkhd creation_time');
    $modifyDate = $win->readU64BE()->toInt('tkhd modification_time');
    $trackId    = $win->readU32BE();
    $win->read(4); // reserved
    $duration   = $win->readU64BE()->toInt('tkhd duration');
} else {
    $createDate = $win->readU32BE();
    $modifyDate = $win->readU32BE();
    $trackId    = $win->readU32BE();
    $win->read(4); // reserved
    $duration   = $win->readU32BE();
}

// trackId validation (already exists)...

$win->read(8); // reserved

$layer = $this->decodeSigned16($win->readU16BE());
$win->read(2); // alternate group
$volumeRaw = $win->readU16BE();
$volume    = $volumeRaw / 256.0;

$win->read(2); // reserved

// Matrix (36 bytes) — read raw values for rotation, format for display
$matrixRaw = [];
for ($i = 0; $i < 9; ++$i) {
    $matrixRaw[] = $win->readU32BE();
}
$matrix   = $this->formatMatrix($matrixRaw);
$rotation = $this->computeRotation($matrixRaw);

// ... width/height as before ...

return [
    'width'            => $width,
    'height'           => $height,
    'isEnabledInMovie' => $isEnabledInMovie,
    'createDate'       => $createDate,
    'modifyDate'       => $modifyDate,
    'trackId'          => $trackId,
    'duration'         => $duration,
    'layer'            => $layer,
    'volume'           => $volume,
    'rotation'         => $rotation,
    'matrix'           => $matrix,
];
```

- [ ] **Step 4: Add `computeRotation()` helper**

```php
/**
 * Computes rotation in degrees from the first four matrix values (a, b, c, d).
 *
 * Uses atan2(b, a) and normalizes to 0, 90, 180, 270.
 *
 * @param list<int> $matrixRaw Nine raw 32-bit matrix values.
 */
private function computeRotation(array $matrixRaw): int
{
    $a = $this->decodeSigned16_16($matrixRaw[0]);
    $b = $this->decodeSigned16_16($matrixRaw[1]);

    $radians = atan2($b, $a);
    $degrees = (int) round($radians * 180.0 / M_PI);

    // Normalize to 0..359
    return (($degrees % 360) + 360) % 360;
}
```

- [ ] **Step 5: Add `decodeSigned16()` helper**

```php
private function decodeSigned16(int $value): int
{
    return $value >= 0x8000 ? $value - 0x10000 : $value;
}
```

- [ ] **Step 6: Reuse `formatMatrix()` and `readMatrixRaw()` from Task 2**

These helpers were already added to `TrackMediaParser` in Task 2 Step 5. Use them directly:
```php
$matrixRaw = $this->readMatrixRaw($win);
$matrix    = $this->formatMatrix($matrixRaw);
$rotation  = $this->computeRotation($matrixRaw);
```

- [ ] **Step 7: Update `parseTrak()` to destructure new tkhd shape**

Where `parseTrak()` currently does:
```php
[$tkhdWidth, $tkhdHeight, $isEnabledInMovie] = $this->parseTkhd($child);
```

Change to:
```php
$tkhdResult       = $this->parseTkhd($child);
$tkhdWidth        = $tkhdResult['width'];
$tkhdHeight       = $tkhdResult['height'];
$isEnabledInMovie = $tkhdResult['isEnabledInMovie'];
```

- [ ] **Step 8: Thread tkhd metadata into track keys**

In `buildVideoTrackKeys()` and `buildAudioTrackKeys()`, add a new `$tkhdResult` parameter and merge the tkhd metadata:

```php
private function buildVideoTrackKeys(array $sampleInfo, ?int $tkhdWidth, ?int $tkhdHeight, array $tkhdResult): array
{
    // ... existing keys ...

    // tkhd-derived metadata
    $trackKeys[QuickTimeMeta::TRACK_CREATE_DATE_KEY] = $tkhdResult['createDate'];
    $trackKeys[QuickTimeMeta::TRACK_MODIFY_DATE_KEY] = $tkhdResult['modifyDate'];
    $trackKeys[QuickTimeMeta::TRACK_ID_KEY]          = $tkhdResult['trackId'];
    $trackKeys[QuickTimeMeta::TRACK_DURATION_KEY]    = $tkhdResult['duration'];
    $trackKeys[QuickTimeMeta::TRACK_LAYER_KEY]       = $tkhdResult['layer'];
    $trackKeys[QuickTimeMeta::TRACK_VOLUME_KEY]      = $tkhdResult['volume'];
    $trackKeys[QuickTimeMeta::ROTATION_KEY]          = $tkhdResult['rotation'];
    $trackKeys[QuickTimeMeta::TRACK_MATRIX_KEY]      = $tkhdResult['matrix'];

    return $trackKeys;
}
```

- [ ] **Step 9: Write tests for 0deg, 180deg, 270deg rotation**

Test each standard rotation matrix and verify the returned rotation value.

- [ ] **Step 10: Write version 1 tkhd test (64-bit timestamps)**

- [ ] **Step 11: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 12: Commit**

```
GH-XXX: Extract tkhd metadata (timestamps, rotation, duration, layer, volume)
```

---

## Task 4: Extract mdhd Metadata (Duration, Language)

Currently `parseMdhd()` delegates entirely to `parseTimescaleHeader()` and reads nothing else. The mdhd box also contains duration and a packed ISO 639-2/T language code.

**Files:**
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:510-513`
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:527-594` (parseMdia to thread mdhd data)
- Modify: `tests/Parse/IsoBmff/TrackMediaParserTest.php`

**Spec reference:** ISO/IEC 14496-12 §8.4.2. After timescale: duration (4 or 8 bytes), language (2 bytes packed ISO 639-2/T), quality (2 bytes reserved).

**Language decoding:** Each of the three 5-bit characters represents a letter (0=pad, 1='a', ..., 26='z'). Extract with bit shifts: `chr(0x60 + ((packed >> 10) & 0x1F))` etc.

- [ ] **Step 1: Write failing test for mdhd language extraction**

Build an mdhd v0 box with language code for "eng" (packed: (5 << 10) | (14 << 5) | 7 = 0x15C7) and assert the parser returns `'language' => 'eng'`.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL

- [ ] **Step 3: Extend `parseMdhd()` to return metadata**

```php
/**
 * @return array{createDate: int, modifyDate: int, timescale: int, duration: int, language: string}
 */
private function parseMdhd(BoxDescriptor $mdhd): array
{
    $header = $this->parseTimescaleHeader($mdhd, 24, 36, 1901, 1903, 1904, 1902, 1905);
    $win    = $mdhd->window;

    // Duration
    $duration = ($header['version'] === 1) ? $win->readU64BE()->toInt('duration') : $win->readU32BE();

    // Language: packed ISO 639-2/T (3x5-bit characters, bit 15 = pad)
    $packed  = $win->readU16BE();
    $language = $this->decodeIso639Language($packed);

    // Quality (2 bytes, reserved) — skip
    // $win->readU16BE();

    return [
        'createDate' => $header['createDate'],
        'modifyDate' => $header['modifyDate'],
        'timescale'  => $header['timescale'],
        'duration'   => $duration,
        'language'   => $language,
    ];
}
```

- [ ] **Step 4: Add `decodeIso639Language()` helper**

```php
/**
 * Decodes a packed ISO 639-2/T language code (3x5-bit characters).
 *
 * ISO/IEC 14496-12 §8.4.2: bit 15 is pad (0), bits 14-10 = char1,
 * bits 9-5 = char2, bits 4-0 = char3. Each 5-bit value maps to
 * 0x60 + value ('a' = 1, 'z' = 26).
 */
private function decodeIso639Language(int $packed): string
{
    $c1 = ($packed >> 10) & 0x1F;
    $c2 = ($packed >> 5) & 0x1F;
    $c3 = $packed & 0x1F;

    // Value 0 means "undetermined" — return 'und'
    if (($c1 === 0) && ($c2 === 0) && ($c3 === 0)) {
        return 'und';
    }

    return chr(0x60 + $c1) . chr(0x60 + $c2) . chr(0x60 + $c3);
}
```

- [ ] **Step 5: Thread mdhd data through `parseMdia()` return**

Change `parseMdia()` to also return the mdhd data so `parseTrak()` can include it in track keys:

```php
// In parseMdia(), where parseMdhd() is called:
$mdhdData = $this->parseMdhd($child);

// Extend return to include mdhd data:
return [$handler, $handlerName, $sampleInfo, $mdhdData];
```

- [ ] **Step 6: Thread mdhd keys into track key building**

In `parseTrak()`, destructure the new 4th element and pass it to `buildVideoTrackKeys()`/`buildAudioTrackKeys()`:

```php
$trackKeys[QuickTimeMeta::MEDIA_CREATE_DATE_KEY]  = $mdhdData['createDate'];
$trackKeys[QuickTimeMeta::MEDIA_MODIFY_DATE_KEY]  = $mdhdData['modifyDate'];
$trackKeys[QuickTimeMeta::MEDIA_TIME_SCALE_KEY]   = $mdhdData['timescale'];
$trackKeys[QuickTimeMeta::MEDIA_DURATION_KEY]     = $mdhdData['duration'];
$trackKeys[QuickTimeMeta::MEDIA_LANGUAGE_CODE_KEY] = $mdhdData['language'];
```

- [ ] **Step 7: Write test for "und" (undetermined) language**

Test with packed value 0x0000 → returns `'und'`.

- [ ] **Step 8: Write test for mdhd v1 (64-bit duration)**

- [ ] **Step 9: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 10: Commit**

```
GH-XXX: Extract mdhd metadata (timestamps, duration, language code)
```

---

## Task 5: Expose Video Bit Depth

`VideoSampleEntryParser::parseVideoSampleEntry()` already reads `$depth` (line 107) and validates it, but does not include it in the return array (lines 117-125).

**Files:**
- Modify: `src/Parse/IsoBmff/VideoSampleEntryParser.php:117-125`
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:156-193` (buildVideoTrackKeys)
- Modify: `tests/Parse/IsoBmff/VideoSampleEntryParserTest.php`

- [ ] **Step 1: Write failing test asserting `depth` is in result**

```php
#[Test]
public function parsesVideoSampleEntryWithDepth(): void
{
    $data   = $this->buildVideoSampleData(width: 1920, height: 1080);
    $win    = $this->createWindow($data);
    $parser = new VideoSampleEntryParser();

    $result = $parser->parseVideoSampleEntry($win, 70, 'avc1');

    self::assertArrayHasKey('depth', $result);
    self::assertSame(24, $result['depth']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL (key 'depth' not found)

- [ ] **Step 3: Add `depth` to the return array**

In `VideoSampleEntryParser.php`, change line 117-125 from:

```php
return [
    'format'               => $normalizedFormat,
    'width'                => $width,
    'height'               => $height,
    'horizontalResolution' => $horizontalResolution,
    'verticalResolution'   => $verticalResolution,
    'frameCount'           => $frameCount,
    'compressorName'       => $compressor,
];
```

to:

```php
return [
    'format'               => $normalizedFormat,
    'width'                => $width,
    'height'               => $height,
    'horizontalResolution' => $horizontalResolution,
    'verticalResolution'   => $verticalResolution,
    'frameCount'           => $frameCount,
    'compressorName'       => $compressor,
    'depth'                => $depth,
];
```

- [ ] **Step 4: Thread depth into `buildVideoTrackKeys()`**

In `TrackMediaParser.php:buildVideoTrackKeys()`, add:

```php
if (isset($sampleInfo['depth'])) {
    $trackKeys[QuickTimeMeta::VIDEO_BIT_DEPTH_KEY] = $sampleInfo['depth'];
}
```

- [ ] **Step 5: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 6: Commit**

```
GH-XXX: Expose video bit depth from sample entry
```

---

## Task 6: Extract vmhd and smhd Fields

Currently `parseMinf()` detects vmhd/smhd presence but does not parse their content. Extract graphicsMode + opColor from vmhd, and balance from smhd.

**Files:**
- Modify: `src/Parse/IsoBmff/TrackMediaParser.php:604-670` (parseMinf)
- Modify: `tests/Parse/IsoBmff/TrackMediaParserTest.php`

**Spec reference:**
- vmhd: FullBox(v=0, flags=0x0001), graphicsMode(u16), opColor(3 × u16) = 12 bytes content
- smhd: FullBox(v=0, flags=0), balance(s16, 8.8 fixed), reserved(u16) = 8 bytes content

- [ ] **Step 1: Write failing test for vmhd graphicsMode extraction**

Build a minf box containing a vmhd with graphicsMode=0x0040 (ditherCopy) and opColor={0x8000, 0x8000, 0x8000}. Assert these values are returned.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL

- [ ] **Step 3: Add `parseVmhd()` and `parseSmhd()` methods**

```php
/**
 * Parses video media information header (vmhd).
 *
 * QuickTime File Format 2012, "Video Media Information Header Atom":
 * FullBox with version=0 and flags=0x000001 ("no lean ahead"),
 * followed by graphicsMode(u16) and opColor(3 × u16).
 *
 * ISO/IEC 14496-12 §12.1.2 requires flags=1 for vmhd. Tolerate
 * flags=0 as well (Postel's Law) since some encoders omit the flag.
 *
 * @return array{graphicsMode: int, opColor: string}
 */
private function parseVmhd(BoxDescriptor $vmhd): array
{
    $win = $vmhd->window;
    $win->seek(0);

    if ($vmhd->contentSize < 12) {
        throw new ParseError('vmhd box truncated', 2101);
    }

    $header = $this->boxNavigator->readFullBoxHeader($win);

    if ($header->version !== 0) {
        throw new ParseError('unsupported vmhd box version', 2102);
    }

    // ISO 14496-12 §12.1.2: vmhd flags should be 0x000001
    // Tolerate 0x000000 for compatibility with non-conforming files
    if (($header->flags !== 0) && ($header->flags !== 1)) {
        throw new ParseError('unsupported vmhd box flags', 2106);
    }

    $graphicsMode = $win->readU16BE();
    $r = $win->readU16BE();
    $g = $win->readU16BE();
    $b = $win->readU16BE();

    return [
        'graphicsMode' => $graphicsMode,
        'opColor'      => sprintf('%d %d %d', $r, $g, $b),
    ];
}

/**
 * Parses sound media information header (smhd).
 *
 * QuickTime File Format 2012, "Sound Media Information Header Atom":
 * balance(s16 8.8 fixed-point).
 *
 * @return array{balance: float}
 */
private function parseSmhd(BoxDescriptor $smhd): array
{
    $win = $smhd->window;
    $win->seek(0);

    if ($smhd->contentSize < 8) {
        throw new ParseError('smhd box truncated', 2103);
    }

    $header = $this->boxNavigator->readFullBoxHeader($win);

    if ($header->version !== 0) {
        throw new ParseError('unsupported smhd box version', 2104);
    }

    if ($header->flags !== 0) {
        throw new ParseError('unsupported smhd box flags', 2105);
    }

    $balanceRaw = $win->readU16BE();
    $balance    = $this->decodeSigned16($balanceRaw) / 256.0;

    return ['balance' => $balance];
}
```

- [ ] **Step 4: Call parse methods from `parseMinf()` and extend return type**

In `parseMinf()`, where vmhd/smhd presence is checked (line 640-647), change from:
```php
$mediaHdrType = $child->type;
```
to:
```php
$mediaHdrType = $child->type;

if ($child->type === BoxType::VMHD->value) {
    $mediaHdrInfo = $this->parseVmhd($child);
} elseif ($child->type === BoxType::SMHD->value) {
    $mediaHdrInfo = $this->parseSmhd($child);
}
```

Initialize `$mediaHdrInfo = [];` at the top of `parseMinf()`.

Change `parseMinf()` return type from `SampleEntryMap` to:
```php
/**
 * @return array{sampleInfo: SampleEntryMap, mediaHdrInfo: array<string, int|float|string>}
 */
```

Return `['sampleInfo' => $result, 'mediaHdrInfo' => $mediaHdrInfo]` instead of just `$result`.

Update `parseMdia()` where it calls `parseMinf()` (line 590):
```php
$minfResult = $this->parseMinf($child, $handler);
$sampleInfo = $minfResult['sampleInfo'];
$mediaHdrInfo = $minfResult['mediaHdrInfo'];
```

Extend `parseMdia()` return to include `$mediaHdrInfo` as a 4th element:
```php
return [$handler, $handlerName, $sampleInfo, $mediaHdrInfo];
```

Update `parseTrak()` to destructure the 4th element and pass it to `buildVideoTrackKeys()`/`buildAudioTrackKeys()`.

- [ ] **Step 5: Thread vmhd/smhd into track keys**

In `buildVideoTrackKeys()`:
```php
if (isset($mediaHdrInfo['graphicsMode'])) {
    $trackKeys[QuickTimeMeta::GRAPHICS_MODE_KEY] = $mediaHdrInfo['graphicsMode'];
}
if (isset($mediaHdrInfo['opColor'])) {
    $trackKeys[QuickTimeMeta::OP_COLOR_KEY] = $mediaHdrInfo['opColor'];
}
```

In `buildAudioTrackKeys()`:
```php
if (isset($mediaHdrInfo['balance'])) {
    $trackKeys[QuickTimeMeta::BALANCE_KEY] = $mediaHdrInfo['balance'];
}
```

- [ ] **Step 6: Write smhd balance test**

Test with balance=0 (center) and balance=0x0080 (0.5, right-shifted).

- [ ] **Step 7: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 8: Commit**

```
GH-XXX: Extract vmhd graphicsMode/opColor and smhd balance
```

---

## Task 7: Add Missing udta Text Atoms

The `UDTA_TEXT_KEYS` map in `QuickTimeMetadataDecoder.php` has 13 entries. The spec defines ~30. Add the missing ones.

**Files:**
- Modify: `src/Parse/IsoBmff/QuickTimeMetadataDecoder.php:47-61`
- Modify: `tests/Parse/IsoBmff/QuickTimeMetadataDecoderTest.php`

- [ ] **Step 1: Write failing test for a new udta text atom (e.g. ©com)**

Build a udta container with a `©com` text atom containing "Hans Zimmer". Parse it and assert the QuickTime key `com.apple.quicktime.composer` is set to "Hans Zimmer".

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL (©com not in UDTA_TEXT_KEYS, so parseUdtaTextAtom ignores it)

- [ ] **Step 3: Add all missing udta text atoms**

Extend the `UDTA_TEXT_KEYS` constant:

```php
private const array UDTA_TEXT_KEYS = [
    // Existing entries
    "\xA9nam" => 'com.apple.quicktime.title',
    "\xA9ART" => 'com.apple.quicktime.artist',
    "\xA9alb" => 'com.apple.quicktime.album',
    "\xA9cmt" => 'com.apple.quicktime.comment',
    "\xA9day" => 'com.apple.quicktime.creationDate',
    "\xA9cpy" => 'com.apple.quicktime.copyright',
    "\xA9too" => 'com.apple.quicktime.software',
    "\xA9gen" => 'com.apple.quicktime.genre',
    "\xA9mak" => 'com.apple.quicktime.make',
    "\xA9mod" => 'com.apple.quicktime.model',
    "\xA9swr" => 'com.apple.quicktime.software',
    "\xA9wrt" => 'com.apple.quicktime.author',
    "\xA9xyz" => 'com.apple.quicktime.location.ISO6709',

    // New entries from QuickTime File Format 2012 §2 "User Data Atoms"
    "\xA9arg" => 'com.apple.quicktime.arranger',
    "\xA9ark" => 'com.apple.quicktime.arrangerKeywords',
    "\xA9cok" => 'com.apple.quicktime.composerKeywords',
    "\xA9com" => 'com.apple.quicktime.composer',
    "\xA9dir" => 'com.apple.quicktime.director',
    "\xA9ed1" => 'com.apple.quicktime.editDate1',
    "\xA9ed2" => 'com.apple.quicktime.editDate2',
    "\xA9ed3" => 'com.apple.quicktime.editDate3',
    "\xA9ed4" => 'com.apple.quicktime.editDate4',
    "\xA9ed5" => 'com.apple.quicktime.editDate5',
    "\xA9ed6" => 'com.apple.quicktime.editDate6',
    "\xA9ed7" => 'com.apple.quicktime.editDate7',
    "\xA9ed8" => 'com.apple.quicktime.editDate8',
    "\xA9ed9" => 'com.apple.quicktime.editDate9',
    "\xA9fmt" => 'com.apple.quicktime.format',
    "\xA9inf" => 'com.apple.quicktime.information',
    "\xA9isr" => 'com.apple.quicktime.ISRCCode',
    "\xA9lab" => 'com.apple.quicktime.recordLabel',
    "\xA9lal" => 'com.apple.quicktime.recordLabelURL',
    "\xA9mal" => 'com.apple.quicktime.makerURL',
    "\xA9nak" => 'com.apple.quicktime.titleKeywords',
    "\xA9pdk" => 'com.apple.quicktime.producerKeywords',
    "\xA9phg" => 'com.apple.quicktime.recordingCopyright',
    "\xA9prd" => 'com.apple.quicktime.producer',
    "\xA9prf" => 'com.apple.quicktime.performers',
    "\xA9prk" => 'com.apple.quicktime.mainArtistKeywords',
    "\xA9prl" => 'com.apple.quicktime.mainArtistURL',
    "\xA9req" => 'com.apple.quicktime.requirements',
    "\xA9snk" => 'com.apple.quicktime.subtitleKeywords',
    "\xA9snm" => 'com.apple.quicktime.subtitle',
    "\xA9src" => 'com.apple.quicktime.sourceCredits',
    "\xA9swf" => 'com.apple.quicktime.songwriter',
    "\xA9swk" => 'com.apple.quicktime.songwriterKeywords',
];
```

- [ ] **Step 4: Write tests for representative new atoms**

Test at least: `©com`, `©dir`, `©prd`, `©prf`, `©snm`, `©inf`. One test per atom, or a data provider covering all new atoms.

- [ ] **Step 5: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 6: Commit**

```
GH-XXX: Add missing udta text atoms from QuickTime spec
```

---

## Task 8: Add Non-Text udta Atoms (LOOP, SelO, AllF, WLOC, ptv)

These are binary/integer atoms in the udta container that are not text-based.

**Files:**
- Modify: `src/Parse/IsoBmff/QuickTimeMetadataDecoder.php`
- Modify: `src/Parse/IsoBmff/IsoBmffParser.php:358-378` (parseUdtaBox)
- Modify: `src/Model/QuickTime/QuickTimeMeta.php`
- Modify: `tests/Parse/IsoBmff/QuickTimeMetadataDecoderTest.php`

**Spec reference:**
- `LOOP`: 4-byte integer. 0 = normal, 1 = palindromic looping
- `SelO`: 1-byte flag. Non-zero = play selection only
- `AllF`: 1-byte flag. Non-zero = play all frames regardless of timing
- `WLOC`: Two 16-bit integers (x, y) = default window location
- `ptv `: 16-byte structure for print-to-video settings

- [ ] **Step 1: Add key constants**

In `QuickTimeMeta.php`:
```php
public const string LOOP_KEY = 'com.apple.quicktime.loopStyle';
public const string PLAY_SELECTION_ONLY_KEY = 'com.apple.quicktime.playSelectionOnly';
public const string PLAY_ALL_FRAMES_KEY = 'com.apple.quicktime.playAllFrames';
public const string WINDOW_LOCATION_KEY = 'com.apple.quicktime.windowLocation';
```

Add corresponding KEY_ALIASES entries.

- [ ] **Step 2: Write failing test for LOOP atom**

Build a udta container with a `LOOP` atom (4 bytes, value=1). Assert key `com.apple.quicktime.loopStyle` is set to 1.

- [ ] **Step 3: Run test to verify it fails**

Run: `make unit`
Expected: FAIL

- [ ] **Step 4: Add `parseUdtaBinaryAtom()` method**

In `QuickTimeMetadataDecoder.php`:

```php
/**
 * Recognized non-text user data atoms mapped to metadata keys and their expected sizes.
 *
 * @var array<string, array{key: string, size: int, type: string}>
 */
private const array UDTA_BINARY_KEYS = [
    'LOOP' => ['key' => 'com.apple.quicktime.loopStyle', 'size' => 4, 'type' => 'u32'],
    'SelO' => ['key' => 'com.apple.quicktime.playSelectionOnly', 'size' => 1, 'type' => 'u8'],
    'AllF' => ['key' => 'com.apple.quicktime.playAllFrames', 'size' => 1, 'type' => 'u8'],
    "WLOC" => ['key' => 'com.apple.quicktime.windowLocation', 'size' => 4, 'type' => 'u16pair'],
];

/**
 * Parses recognized non-text user-data binary atoms.
 */
public function parseUdtaBinaryAtom(BoxDescriptor $atom, IsoBmffParseContext $context): void
{
    $spec = self::UDTA_BINARY_KEYS[$atom->type] ?? null;

    if ($spec === null || $atom->contentSize < $spec['size']) {
        return;
    }

    $win = $atom->window;
    $win->seek(0);

    $value = match ($spec['type']) {
        'u32'     => $win->readU32BE(),
        'u8'      => $win->readU8(),
        'u16pair' => sprintf('%d %d', $win->readU16BE(), $win->readU16BE()),
        default   => null,
    };

    if ($value === null) {
        return;
    }

    $key = $spec['key'];

    if (!array_key_exists($key, $context->qtKeys)) {
        $context->qtKeys[$key] = $value;
    }
}
```

- [ ] **Step 5: Call `parseUdtaBinaryAtom()` from `parseUdtaBox()`**

In `IsoBmffParser.php:parseUdtaBox()`, add an `elseif` before the final `else` that calls `parseUdtaTextAtom`. Use a public method on the decoder to check for binary atoms — avoid duplicating the list of known atom types:

Add to `QuickTimeMetadataDecoder`:
```php
/**
 * Returns whether the given atom type is a recognized binary udta atom.
 */
public function isUdtaBinaryAtom(string $type): bool
{
    return array_key_exists($type, self::UDTA_BINARY_KEYS);
}
```

In `IsoBmffParser.php:parseUdtaBox()`:
```php
} elseif ($this->quickTimeDecoder->isUdtaBinaryAtom($child->type)) {
    $this->quickTimeDecoder->parseUdtaBinaryAtom($child, $context);
} else {
    $this->quickTimeDecoder->parseUdtaTextAtom($child, $context);
}
```

- [ ] **Step 6: Write tests for SelO, AllF, WLOC**

- [ ] **Step 7: Run all tests**

Run: `make test`
Expected: PASS

- [ ] **Step 8: Commit**

```
GH-XXX: Add non-text udta atoms (LOOP, SelO, AllF, WLOC)
```

---

## Task 9: Format Script — Render New QuickTime Fields

Update the format script to display the new metadata fields with ExifTool-compatible formatting.

**Files:**
- Modify: `scripts/imagemeta-format.php`

**Key considerations:**
- Mac epoch timestamps → format as `YYYY:MM:DD HH:MM:SS` (ExifTool convention)
- Duration → format as `X.XX s` with timescale conversion
- Rotation → format as integer degrees
- Matrix → space-separated decimal values (already formatted by parser)
- Language code → 3-letter ISO 639-2/T string
- Rate → `X.X` (e.g., "1")
- Volume → `X.XX%` (e.g., "100.00%")

- [ ] **Step 1: Add `QUICKTIME_KEY_LABELS` entries for new fields**

In the format script's `QUICKTIME_KEY_LABELS` constant, add entries for all new metadata keys to map them to ExifTool-style display labels:

```php
private const array QUICKTIME_KEY_LABELS = [
    // Existing entries...
    QuickTimeMeta::MAJOR_BRAND_KEY           => '0x0000 Major Brand',
    QuickTimeMeta::MINOR_VERSION_KEY         => '0x0001 Minor Version',
    QuickTimeMeta::COMPATIBLE_BRANDS_KEY     => '0x0002 Compatible Brands',

    // mvhd fields
    QuickTimeMeta::CREATE_DATE_KEY           => 'Create Date',
    QuickTimeMeta::MODIFY_DATE_KEY           => 'Modify Date',
    QuickTimeMeta::TIME_SCALE_KEY            => 'Time Scale',
    QuickTimeMeta::DURATION_KEY              => 'Duration',
    QuickTimeMeta::PREFERRED_RATE_KEY        => 'Preferred Rate',
    QuickTimeMeta::PREFERRED_VOLUME_KEY      => 'Preferred Volume',
    QuickTimeMeta::MATRIX_STRUCTURE_KEY      => 'Matrix Structure',
    QuickTimeMeta::NEXT_TRACK_ID_KEY         => 'Next Track ID',
    QuickTimeMeta::PREVIEW_TIME_KEY          => 'Preview Time',
    QuickTimeMeta::PREVIEW_DURATION_KEY      => 'Preview Duration',
    QuickTimeMeta::POSTER_TIME_KEY           => 'Poster Time',
    QuickTimeMeta::SELECTION_TIME_KEY        => 'Selection Time',
    QuickTimeMeta::SELECTION_DURATION_KEY    => 'Selection Duration',
    QuickTimeMeta::CURRENT_TIME_KEY          => 'Current Time',

    // tkhd fields
    QuickTimeMeta::TRACK_CREATE_DATE_KEY     => 'Track Create Date',
    QuickTimeMeta::TRACK_MODIFY_DATE_KEY     => 'Track Modify Date',
    QuickTimeMeta::TRACK_ID_KEY              => 'Track ID',
    QuickTimeMeta::TRACK_DURATION_KEY        => 'Track Duration',
    QuickTimeMeta::TRACK_LAYER_KEY           => 'Track Layer',
    QuickTimeMeta::TRACK_VOLUME_KEY          => 'Track Volume',
    QuickTimeMeta::ROTATION_KEY              => 'Rotation',
    QuickTimeMeta::TRACK_MATRIX_KEY          => 'Track Matrix Structure',

    // mdhd fields
    QuickTimeMeta::MEDIA_CREATE_DATE_KEY     => 'Media Create Date',
    QuickTimeMeta::MEDIA_MODIFY_DATE_KEY     => 'Media Modify Date',
    QuickTimeMeta::MEDIA_DURATION_KEY        => 'Media Duration',
    QuickTimeMeta::MEDIA_TIME_SCALE_KEY      => 'Media Time Scale',
    QuickTimeMeta::MEDIA_LANGUAGE_CODE_KEY   => 'Media Language Code',

    // Sample entry fields
    QuickTimeMeta::VIDEO_BIT_DEPTH_KEY       => 'Bit Depth',

    // vmhd/smhd fields
    QuickTimeMeta::GRAPHICS_MODE_KEY         => 'Graphics Mode',
    QuickTimeMeta::OP_COLOR_KEY              => 'Op Color',
    QuickTimeMeta::BALANCE_KEY               => 'Balance',
];
```

- [ ] **Step 2: Add Mac epoch timestamp formatting helper**

```php
/**
 * Converts a Mac epoch timestamp (seconds since 1904-01-01) to ExifTool format.
 *
 * ExifTool displays these as "YYYY:MM:DD HH:MM:SS".
 */
private function formatMacTimestamp(int $macTimestamp): string
{
    // Mac epoch to Unix epoch offset
    $unixTimestamp = $macTimestamp - 2_082_844_800;

    $dt = new DateTimeImmutable('@' . $unixTimestamp, new DateTimeZone('UTC'));

    return $dt->format('Y:m:d H:i:s');
}
```

- [ ] **Step 3: Add value formatting for QuickTime keys**

In `printQuickTimeSection()`, add a value formatting step that formats timestamps, duration, volume, and rate before display:

```php
private function formatQuickTimeValue(string $key, string|int|float|bool $value): string|int|float|bool
{
    // Mac epoch timestamps
    if (is_int($value) && in_array($key, [
        QuickTimeMeta::CREATE_DATE_KEY,
        QuickTimeMeta::MODIFY_DATE_KEY,
        QuickTimeMeta::TRACK_CREATE_DATE_KEY,
        QuickTimeMeta::TRACK_MODIFY_DATE_KEY,
        QuickTimeMeta::MEDIA_CREATE_DATE_KEY,
        QuickTimeMeta::MEDIA_MODIFY_DATE_KEY,
    ], true)) {
        return $this->formatMacTimestamp($value);
    }

    // Volume as percentage
    if (is_float($value) && $key === QuickTimeMeta::PREFERRED_VOLUME_KEY) {
        return sprintf('%.2f%%', $value * 100);
    }

    // Rotation with degree suffix
    if ($key === QuickTimeMeta::ROTATION_KEY) {
        return $value;
    }

    return $value;
}
```

Apply this in `printQuickTimeSection()`:
```php
$data[$label] = $this->formatQuickTimeValue($key, $value);
```

- [ ] **Step 4: Run format script against a test file to verify output**

Run: `make format FILE=tests/fixtures/sample.mov` (or any available test file)
Verify new fields appear in output.

- [ ] **Step 5: Run full CI**

Run: `make test`
Expected: PASS

- [ ] **Step 6: Commit**

```
GH-XXX: Render new QuickTime metadata fields in format script
```

---

## Task 10: Final Integration Test and Cleanup

- [ ] **Step 1: Run full CI pipeline**

Run: `make test`
This runs: phplint -> php-cs-fixer -> rector -> phpstan -> phpunit -> jscpd

Expected: ALL PASS

- [ ] **Step 2: Fix any PHPStan issues**

Common expected issues:
- New return types on `parseMvhd()`, `parseTkhd()`, `parseMdhd()` may require `@phpstan-type` aliases
- Update `@phpstan-import-type` annotations in dependent classes
- Verify all new KEY_ALIASES entries have correct PHPStan array types

- [ ] **Step 3: Fix any php-cs-fixer issues**

Run: `make cgl`

Expected: Code style fixes auto-applied, then `make cgl-check` passes.

- [ ] **Step 4: Fix any rector issues**

Run: `make rector`

Expected: Any auto-fixable rector suggestions applied.

- [ ] **Step 5: Run full CI again to confirm green**

Run: `make test`
Expected: ALL PASS

- [ ] **Step 6: Final commit**

```
GH-XXX: Fix CI issues from QuickTime metadata implementation
```

---

## Error Code Allocation

New error codes for this plan (all > 2100, the current max):

| Code | Location | Description |
|------|----------|-------------|
| 2101 | `parseVmhd()` | vmhd box truncated |
| 2102 | `parseVmhd()` | unsupported vmhd version |
| 2103 | `parseSmhd()` | smhd box truncated |
| 2104 | `parseSmhd()` | unsupported smhd version |
| 2105 | `parseSmhd()` | unsupported smhd flags |
| 2106 | `parseVmhd()` | unsupported vmhd flags (neither 0 nor 1) |

---

## Dependency Graph

```
Task 1 (constants)
  ├── Task 2 (mvhd) ──────┐
  ├── Task 3 (tkhd) ──────┤
  ├── Task 4 (mdhd) ──────┤
  ├── Task 5 (depth) ─────┤
  ├── Task 6 (vmhd/smhd) ─┤
  ├── Task 7 (udta text) ──┤
  └── Task 8 (udta binary) ┤
                            └── Task 9 (format script)
                                  └── Task 10 (CI cleanup)
```

Task 1 must go first. Tasks 2-8 can be done in any order (or in parallel) after Task 1. Task 9 depends on Tasks 2-8. Task 10 is the final gate.
