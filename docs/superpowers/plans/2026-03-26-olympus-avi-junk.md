# Olympus AVI JUNK Chunk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse Olympus camera metadata from RIFF/AVI `JUNK` chunks (with `OLYMDigital Camera` signature) so that camera make/model, f-number, and capture dates flow into StructuredMetadata and the format script.

**Architecture:** The RiffParser gains a `JUNK` chunk handler that detects the 18-byte `OLYMDigital Camera` signature and delegates to `OlympusJunkParser`. Parsed data is stored as `OlympusCameraTags` (immutable value object), propagated through `RiffParseResult` → `Metadata` → factories, rendered with byte offsets in the format script. Follows the exact pattern established by the Nikon ncdt/nctg implementation (GH-2289).

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan max level, php-cs-fixer (Symfony ruleset)

**Issue:** GH-2301

---

## Current State

After GH-2289, imagemeta already extracts from this Olympus AVI file:
- `temporal->original`: `2014-06-06 11:08:45` (via RIFF IDIT chunk — already working)
- `device->software`: `OLYMPUS VG110,D700` (via RIFF INFO ISFT — already working)

**Still missing** (only in Olympus JUNK chunk):
- `camera->make`: NULL → should be `OLYMPUS DIGITAL CAMERA`
- `camera->model`: NULL → should be `D4488`
- `exposure->fNumber`: NULL → should be `3.0`
- JUNK DateTime1/DateTime2 as temporal fallback for files without IDIT

---

## Olympus JUNK Binary Format

The Olympus data lives in a standard RIFF `JUNK` chunk (normally used for padding) with a vendor-specific signature. The format uses **fixed byte offsets**, NOT TLV.

**Signature:** `OLYMDigital Camera` (18 bytes ASCII at offset 0, no null separator)

| Offset | Name | Format | Size | Notes |
|--------|------|--------|------|-------|
| 0x0000 | Signature | string | 18 | `OLYMDigital Camera` |
| 0x0012 | Make | string[24] | 24 | Null-padded, e.g. `OLYMPUS DIGITAL CAMERA` |
| 0x002c | Model | string[24] | 24 | Internal code, e.g. `D4488` |
| 0x005e | FNumber | rational64u | 8 | 2x u32 LE (num/den), e.g. 300/100 = 3.0 |
| 0x0083 | DateTime1 | string[24] | 24 | C ctime, e.g. `Fri Jun  6 11:08:45 2014` |
| 0x009d | DateTime2 | string[24] | 24 | Same format, typically same value |
| 0x0129 | Thumbnail | subdirectory | var | Width(u16) + Height(u16) + Length(u32) + JPEG — out of scope |

**Byte order:** Little-endian.

**Reference:** ExifTool `Olympus.pm` `%Image::ExifTool::Olympus::AVI`, `RIFF.pm` line 445 (JUNK signature detection). Pentax `Junk2` is nearly identical (signature `PENTDigital Camera`, thumbnail at 0x012b).

Verified against `/volume1/Fotos/Eric/Kita/Videos/2014-06-06_11-08-45-000.avi` (Olympus VG110/D700).

---

## File Structure

### New files
| File | Responsibility |
|------|---------------|
| `src/Model/Riff/OlympusCameraTags.php` | Immutable value object for parsed JUNK fields + `entries` for format rendering |
| `src/Parse/Riff/OlympusJunkParser.php` | Fixed-offset parser for Olympus JUNK payloads |
| `src/Model/Riff/OlympusAviLookup.php` | Typed accessor facade for factory fallback chains |
| `tests/Parse/Riff/OlympusJunkParserTest.php` | Parser unit tests |
| `tests/Model/Riff/OlympusAviLookupTest.php` | Lookup accessor tests |

### Modified files
| File | Change |
|------|--------|
| `src/Parse/Riff/RiffParser.php` | Add `'JUNK'` case in `dispatchChunk()`, new `handleJunkChunk()` method |
| `src/Parse/Riff/RiffParseResult.php` | Add `?OlympusCameraTags $olympusCameraTags` |
| `src/Model/MetadataBuilder.php` | Add `olympusCameraTags` field, wire through `withRiff()` and `build()` |
| `src/Model/Metadata.php` | Add `?OlympusCameraTags $olympusCameraTags` + `olympusAviLookup()` |
| `src/MetadataReader.php` | Pass `$result->olympusCameraTags` to `withRiff()` |
| `src/Factory/Structured/CameraFactory.php` | Add Olympus make/model fallback |
| `src/Factory/Structured/ExposureFactory.php` | Add Olympus fNumber fallback |
| `src/Factory/Structured/TemporalFactory.php` | Add Olympus DateTime1/DateTime2 fallback |
| `scripts/imagemeta-format.php` | New `printOlympusSection()` with byte offsets |
| `tests/Parse/Riff/RiffParserTest.php` | Add JUNK/OLYM extraction test |

---

## Error Code Allocation

New codes start at **2143** (current max = 2142 reserved):

No error codes needed — parser follows Postel's Law (returns null on malformed data).

---

## Task 1: OlympusCameraTags Model + OlympusJunkParser (TDD)

**Files:**
- Create: `src/Model/Riff/OlympusCameraTags.php`
- Create: `tests/Parse/Riff/OlympusJunkParserTest.php`
- Create: `src/Parse/Riff/OlympusJunkParser.php`

- [ ] **Step 1: Create OlympusCameraTags value object**

```php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Immutable value object holding parsed Olympus camera metadata from RIFF/AVI JUNK chunks.
 *
 * The Olympus JUNK chunk uses a fixed-offset binary format with the 18-byte signature
 * "OLYMDigital Camera". Fields are at predefined byte offsets within the payload.
 *
 * Reference: ExifTool Olympus.pm %Image::ExifTool::Olympus::AVI.
 */
final readonly class OlympusCameraTags
{
    /**
     * @param array<int, string> $entries   All parsed fields (byte offset => display string) for format rendering.
     * @param string|null        $make      Camera make (offset 0x0012, string[24]).
     * @param string|null        $model     Camera model internal code (offset 0x002c, string[24]).
     * @param float|null         $fNumber   F-number (offset 0x005e, rational64u).
     * @param string|null        $dateTime1 Capture date in C ctime format (offset 0x0083, string[24]).
     * @param string|null        $dateTime2 Second date stamp (offset 0x009d, string[24]).
     */
    public function __construct(
        public array $entries,
        public ?string $make = null,
        public ?string $model = null,
        public ?float $fNumber = null,
        public ?string $dateTime1 = null,
        public ?string $dateTime2 = null,
    ) {
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Riff;

use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
use MagicSunday\ImageMeta\Parse\Riff\OlympusJunkParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_pad;

#[CoversClass(OlympusJunkParser::class)]
#[UsesClass(OlympusCameraTags::class)]
#[UsesClass(Unpack::class)]
final class OlympusJunkParserTest extends TestCase
{
    #[Test]
    public function parsesOlympusMakeFromJunkPayload(): void
    {
        $payload = $this->buildMinimalOlympusPayload(make: 'OLYMPUS DIGITAL CAMERA');

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('OLYMPUS DIGITAL CAMERA', $result->make);
        self::assertSame('OLYMPUS DIGITAL CAMERA', $result->entries[0x0012]);
    }

    #[Test]
    public function parsesOlympusModelFromJunkPayload(): void
    {
        $payload = $this->buildMinimalOlympusPayload(model: 'D4488');

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('D4488', $result->model);
        self::assertSame('D4488', $result->entries[0x002C]);
    }

    #[Test]
    public function parsesFNumberRational(): void
    {
        $payload = $this->buildMinimalOlympusPayload(fNumberNum: 300, fNumberDen: 100);

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertEqualsWithDelta(3.0, $result->fNumber, 0.001);
    }

    #[Test]
    public function parsesDateTime1InCtimeFormat(): void
    {
        $payload = $this->buildMinimalOlympusPayload(dateTime1: 'Fri Jun  6 11:08:45 2014');

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->dateTime1);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->entries[0x0083]);
    }

    #[Test]
    public function parsesDateTime2(): void
    {
        $payload = $this->buildMinimalOlympusPayload(dateTime2: 'Fri Jun  6 11:08:45 2014');

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->dateTime2);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->entries[0x009D]);
    }

    #[Test]
    public function parsesAllFieldsTogether(): void
    {
        $payload = $this->buildMinimalOlympusPayload(
            make: 'OLYMPUS DIGITAL CAMERA',
            model: 'D4488',
            fNumberNum: 300,
            fNumberDen: 100,
            dateTime1: 'Fri Jun  6 11:08:45 2014',
            dateTime2: 'Fri Jun  6 11:08:45 2014',
        );

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('OLYMPUS DIGITAL CAMERA', $result->make);
        self::assertSame('D4488', $result->model);
        self::assertEqualsWithDelta(3.0, $result->fNumber, 0.001);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->dateTime1);
        self::assertSame('Fri Jun  6 11:08:45 2014', $result->dateTime2);
        self::assertCount(5, $result->entries);
    }

    #[Test]
    public function returnsNullOnInvalidSignature(): void
    {
        // Payload with wrong signature
        $payload = str_pad('NOTOlympusCamera', 0xB5, "\x00");

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullOnTruncatedPayload(): void
    {
        // Only the signature, not enough for any field
        $result = (new OlympusJunkParser())->parse('OLYMDigital Camera');

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullWhenAllFieldsEmpty(): void
    {
        // Payload with valid signature but all-null data fields → no entries → null
        $payload = str_pad('OLYMDigital Camera', 0xB5, "\x00");

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function skipsFNumberWithZeroDenominator(): void
    {
        $payload = $this->buildMinimalOlympusPayload(
            make: 'OLYMPUS DIGITAL CAMERA',
            fNumberNum: 300,
            fNumberDen: 0,
        );

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertNull($result->fNumber);
    }

    #[Test]
    public function handlesNonStandardDayAbbreviation(): void
    {
        // Olympus cameras sometimes write "Wen" instead of "Wed"
        $payload = $this->buildMinimalOlympusPayload(dateTime1: 'Wen Jul  5 10:35:53 2017');

        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Wen Jul  5 10:35:53 2017', $result->dateTime1);
    }

    /**
     * Builds a minimal Olympus JUNK payload with the OLYM signature and fields at correct offsets.
     *
     * Minimum size: 0xB5 (181 bytes) — covers all fields through DateTime2.
     */
    private function buildMinimalOlympusPayload(
        string $make = '',
        string $model = '',
        int $fNumberNum = 0,
        int $fNumberDen = 0,
        string $dateTime1 = '',
        string $dateTime2 = '',
    ): string {
        // Start with 0xB5 null bytes (minimum to cover DateTime2 at 0x9D + 24)
        $payload = str_repeat("\x00", 0xB5);

        // Signature at 0x0000
        $sig = 'OLYMDigital Camera';

        for ($i = 0; $i < 18; ++$i) {
            $payload[$i] = $sig[$i];
        }

        // Make at 0x0012 (string[24])
        if ($make !== '') {
            $padded = str_pad($make, 24, "\x00");

            for ($i = 0; $i < 24; ++$i) {
                $payload[0x12 + $i] = $padded[$i];
            }
        }

        // Model at 0x002C (string[24])
        if ($model !== '') {
            $padded = str_pad($model, 24, "\x00");

            for ($i = 0; $i < 24; ++$i) {
                $payload[0x2C + $i] = $padded[$i];
            }
        }

        // FNumber at 0x005E (rational64u: 2x u32 LE)
        if ($fNumberNum !== 0 || $fNumberDen !== 0) {
            $rational = pack('VV', $fNumberNum, $fNumberDen);

            for ($i = 0; $i < 8; ++$i) {
                $payload[0x5E + $i] = $rational[$i];
            }
        }

        // DateTime1 at 0x0083 (string[24])
        if ($dateTime1 !== '') {
            $padded = str_pad($dateTime1, 24, "\x00");

            for ($i = 0; $i < 24; ++$i) {
                $payload[0x83 + $i] = $padded[$i];
            }
        }

        // DateTime2 at 0x009D (string[24])
        if ($dateTime2 !== '') {
            $padded = str_pad($dateTime2, 24, "\x00");

            for ($i = 0; $i < 24; ++$i) {
                $payload[0x9D + $i] = $padded[$i];
            }
        }

        return $payload;
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `make unit`
Expected: FAIL — `OlympusJunkParser` class not found

- [ ] **Step 4: Implement OlympusJunkParser**

```php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;

use function rtrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * Parses Olympus camera metadata from RIFF/AVI JUNK chunk payloads.
 *
 * Olympus AVI files embed camera metadata in a JUNK chunk with the 18-byte signature
 * "OLYMDigital Camera". Fields are at fixed byte offsets within the payload.
 *
 * Reference: ExifTool Olympus.pm %Image::ExifTool::Olympus::AVI.
 */
final class OlympusJunkParser
{
    /**
     * The 18-byte signature at the start of Olympus JUNK payloads.
     */
    public const string SIGNATURE = 'OLYMDigital Camera';

    /**
     * Length of the signature in bytes.
     */
    private const int SIGNATURE_LENGTH = 18;

    /**
     * Minimum payload size to contain all known fields through DateTime2.
     * DateTime2 starts at 0x009D and is 24 bytes: 0x009D + 24 = 0xB5 (181).
     */
    public const int MIN_PAYLOAD_SIZE = 0xB5;

    // Field offsets
    private const int OFFSET_MAKE       = 0x0012;
    private const int OFFSET_MODEL      = 0x002C;
    private const int OFFSET_F_NUMBER   = 0x005E;
    private const int OFFSET_DATE_TIME1 = 0x0083;
    private const int OFFSET_DATE_TIME2 = 0x009D;

    /**
     * Fixed string field size (all string fields are 24 bytes).
     */
    private const int STRING_FIELD_SIZE = 24;

    /**
     * Parses an Olympus JUNK chunk payload into an OlympusCameraTags value object.
     *
     * Returns null if the signature does not match or the payload is too short.
     * Follows Postel's Law: tolerates empty fields and zero-denominator rationals.
     */
    public function parse(string $payload): ?OlympusCameraTags
    {
        $length = strlen($payload);

        if ($length < self::MIN_PAYLOAD_SIZE) {
            return null;
        }

        if (substr($payload, 0, self::SIGNATURE_LENGTH) !== self::SIGNATURE) {
            return null;
        }

        $entries = [];

        $make = $this->readString($payload, self::OFFSET_MAKE);

        if ($make !== null) {
            $entries[self::OFFSET_MAKE] = $make;
        }

        $model = $this->readString($payload, self::OFFSET_MODEL);

        if ($model !== null) {
            $entries[self::OFFSET_MODEL] = $model;
        }

        $fNumber = $this->readRational($payload, self::OFFSET_F_NUMBER);

        if ($fNumber !== null) {
            $entries[self::OFFSET_F_NUMBER] = sprintf('%g', $fNumber);
        }

        $dateTime1 = $this->readString($payload, self::OFFSET_DATE_TIME1);

        if ($dateTime1 !== null) {
            $entries[self::OFFSET_DATE_TIME1] = $dateTime1;
        }

        $dateTime2 = $this->readString($payload, self::OFFSET_DATE_TIME2);

        if ($dateTime2 !== null) {
            $entries[self::OFFSET_DATE_TIME2] = $dateTime2;
        }

        if ($entries === []) {
            return null;
        }

        return new OlympusCameraTags(
            $entries,
            $make,
            $model,
            $fNumber,
            $dateTime1,
            $dateTime2,
        );
    }

    /**
     * Reads a null-terminated string[24] at the given offset.
     *
     * Strips trailing null bytes and newlines — some Olympus cameras
     * pad date strings with a trailing 0x0a (newline) character.
     */
    private function readString(string $payload, int $offset): ?string
    {
        $raw = substr($payload, $offset, self::STRING_FIELD_SIZE);
        $str = rtrim($raw, "\x00\x0a");

        return $str !== '' ? $str : null;
    }

    /**
     * Reads an unsigned rational (2x u32 LE) at the given offset. Returns null on zero denominator.
     */
    private function readRational(string $payload, int $offset): ?float
    {
        $num = Unpack::int('V', substr($payload, $offset, 4), 'Olympus rational num');
        $den = Unpack::int('V', substr($payload, $offset + 4, 4), 'Olympus rational den');

        if ($den === 0) {
            return null;
        }

        return $num / $den;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make unit`
Expected: PASS

- [ ] **Step 6: Run full CI**

Run: `make test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Model/Riff/OlympusCameraTags.php tests/Parse/Riff/OlympusJunkParserTest.php src/Parse/Riff/OlympusJunkParser.php
git commit -m "GH-2301: Add OlympusCameraTags model and OlympusJunkParser for RIFF JUNK chunks"
```

---

## Task 2: RiffParser JUNK Chunk Integration (TDD)

**Files:**
- Modify: `src/Parse/Riff/RiffParser.php`
- Modify: `src/Parse/Riff/RiffParseResult.php`
- Modify: `tests/Parse/Riff/RiffParserTest.php`

- [ ] **Step 1: Write the failing test in RiffParserTest**

Add imports:
```php
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
use MagicSunday\ImageMeta\Parse\Riff\OlympusJunkParser;
```

Add `#[UsesClass]` attributes:
```php
#[UsesClass(OlympusCameraTags::class)]
#[UsesClass(OlympusJunkParser::class)]
```

Add test method:
```php
#[Test]
public function extractsOlympusCameraTagsFromJunkChunk(): void
{
    // Build Olympus JUNK payload: signature + Make + DateTime1
    $payload = str_repeat("\x00", 0xB5);
    $sig = OlympusJunkParser::SIGNATURE;
    for ($i = 0; $i < 18; ++$i) {
        $payload[$i] = $sig[$i];
    }
    $make = str_pad('OLYMPUS DIGITAL CAMERA', 24, "\x00");
    for ($i = 0; $i < 24; ++$i) {
        $payload[0x12 + $i] = $make[$i];
    }
    $date = str_pad('Fri Jun  6 11:08:45 2014', 24, "\x00");
    for ($i = 0; $i < 24; ++$i) {
        $payload[0x83 + $i] = $date[$i];
    }

    $junkChunk = $this->riffChunk('JUNK', $payload);
    $avi       = $this->riffAviContainer($junkChunk);
    $stream    = $this->createRiffTempStream($avi);
    $result    = (new RiffParser($stream, new RiffParserConfig()))->extract();

    self::assertInstanceOf(OlympusCameraTags::class, $result->olympusCameraTags);
    self::assertSame('OLYMPUS DIGITAL CAMERA', $result->olympusCameraTags->make);
    self::assertSame('Fri Jun  6 11:08:45 2014', $result->olympusCameraTags->dateTime1);
}
```

Add `use function str_pad;` and `use function str_repeat;` if not already imported.

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit`
Expected: FAIL — `olympusCameraTags` property does not exist

- [ ] **Step 3: Add olympusCameraTags to RiffParseResult**

In `src/Parse/Riff/RiffParseResult.php`, add import:
```php
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
```

Add constructor parameter after `$nikonCameraTags`:
```php
public ?OlympusCameraTags $olympusCameraTags = null,
```

- [ ] **Step 4: Add JUNK handling to RiffParser**

In `src/Parse/Riff/RiffParser.php`:

Add import:
```php
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
```

Add field:
```php
private ?OlympusCameraTags $olympusCameraTags = null;
```

In `dispatchChunk()`, add after the `'avih'` case:
```php
case 'JUNK':
    $this->handleJunkChunk($dataOffset, $chunkSize);

    break;
```

Add new private method:
```php
/**
 * Handles a JUNK chunk by checking for vendor signatures.
 *
 * JUNK chunks are normally padding, but some camera vendors embed proprietary
 * metadata. Currently detects Olympus ("OLYMDigital Camera" signature).
 *
 * Reference: ExifTool RIFF.pm line 445.
 */
private function handleJunkChunk(int $dataOffset, int $dataSize): void
{
    // Skip if already parsed — prevents a later non-Olympus JUNK chunk
    // (padding) from overwriting valid Olympus data with null.
    if ($this->olympusCameraTags !== null) {
        return;
    }

    if ($dataSize < OlympusJunkParser::MIN_PAYLOAD_SIZE || $dataSize > $this->config->maxMetadataPayloadSize) {
        return;
    }

    $this->stream->seek($dataOffset);

    try {
        $payload = $this->stream->read($dataSize);
    } catch (BoundsError) {
        return;
    }

    // Delegate to OlympusJunkParser — it validates the OLYM signature internally.
    // Non-Olympus JUNK chunks (padding) are returned as null by the parser.
    $this->olympusCameraTags = (new OlympusJunkParser())->parse($payload);
}
```

Add `use` import for `OlympusJunkParser`. No `use function substr;` needed — signature detection is delegated to the parser.

In `extract()`, add `$this->olympusCameraTags` to the `RiffParseResult` construction:
```php
return new RiffParseResult(
    $this->exifBlobs,
    $this->xmpBlobs,
    $this->infoFields !== [] ? new RiffInfo($this->infoFields) : null,
    $this->aviHeader,
    $this->riffExif,
    $this->nikonCameraTags,
    $this->olympusCameraTags,
);
```

- [ ] **Step 5: Run tests, then full CI**

Run: `make test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Parse/Riff/RiffParser.php src/Parse/Riff/RiffParseResult.php tests/Parse/Riff/RiffParserTest.php
git commit -m "GH-2301: Parse Olympus JUNK chunk with OLYM signature in RIFF parser"
```

---

## Task 3: Metadata Wiring + OlympusAviLookup

**Files:**
- Create: `src/Model/Riff/OlympusAviLookup.php`
- Create: `tests/Model/Riff/OlympusAviLookupTest.php`
- Modify: `src/Model/MetadataBuilder.php`
- Modify: `src/Model/Metadata.php`
- Modify: `src/MetadataReader.php`

- [ ] **Step 1: Write the failing OlympusAviLookup test**

```php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Riff;

use MagicSunday\ImageMeta\Model\Riff\OlympusAviLookup;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OlympusAviLookup::class)]
#[UsesClass(OlympusCameraTags::class)]
final class OlympusAviLookupTest extends TestCase
{
    #[Test]
    public function returnsTypedFieldsFromOlympusCameraTags(): void
    {
        $tags   = new OlympusCameraTags(
            entries: [],
            make: 'OLYMPUS DIGITAL CAMERA',
            model: 'D4488',
            fNumber: 3.0,
            dateTime1: 'Fri Jun  6 11:08:45 2014',
            dateTime2: 'Fri Jun  6 11:08:45 2014',
        );
        $lookup = new OlympusAviLookup($tags);

        self::assertSame('OLYMPUS DIGITAL CAMERA', $lookup->make());
        self::assertSame('D4488', $lookup->model());
        self::assertEqualsWithDelta(3.0, $lookup->fNumber(), 0.001);
        self::assertSame('Fri Jun  6 11:08:45 2014', $lookup->dateTime1());
        self::assertSame('Fri Jun  6 11:08:45 2014', $lookup->dateTime2());
    }

    #[Test]
    public function returnsNullWhenTagsAbsent(): void
    {
        $lookup = new OlympusAviLookup(null);

        self::assertNull($lookup->make());
        self::assertNull($lookup->model());
        self::assertNull($lookup->fNumber());
        self::assertNull($lookup->dateTime1());
        self::assertNull($lookup->dateTime2());
    }
}
```

- [ ] **Step 2: Create OlympusAviLookup**

```php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Typed accessor facade for Olympus AVI Camera Tags in StructuredMetadata factory fallback chains.
 *
 * Analogous to {@see NikonAviLookup} for Nikon AVI fields.
 */
final readonly class OlympusAviLookup
{
    public function __construct(
        private ?OlympusCameraTags $tags,
    ) {
    }

    public function make(): ?string
    {
        return $this->tags?->make;
    }

    public function model(): ?string
    {
        return $this->tags?->model;
    }

    public function fNumber(): ?float
    {
        return $this->tags?->fNumber;
    }

    public function dateTime1(): ?string
    {
        return $this->tags?->dateTime1;
    }

    public function dateTime2(): ?string
    {
        return $this->tags?->dateTime2;
    }
}
```

- [ ] **Step 3: Wire into MetadataBuilder**

In `src/Model/MetadataBuilder.php`:

Add import: `use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;`

Add field: `private ?OlympusCameraTags $olympusCameraTags = null;`

Update `withRiff()` — add `?OlympusCameraTags $olympusCameraTags = null` parameter and assign it.

In `build()`, add `olympusCameraTags: $this->olympusCameraTags,` between `nikonCameraTags:` (line 400) and `jfifSegment:` (line 401):
```php
nikonCameraTags: $this->nikonCameraTags,
olympusCameraTags: $this->olympusCameraTags,   // <-- NEW
jfifSegment: $this->jfifSegment,
```

- [ ] **Step 4: Wire into Metadata**

In `src/Model/Metadata.php`:

Add imports:
```php
use MagicSunday\ImageMeta\Model\Riff\OlympusAviLookup;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
```

Add constructor parameter `public ?OlympusCameraTags $olympusCameraTags = null,` between `$nikonCameraTags` (line 173) and `$jfifSegment` (line 174):
```php
public ?NikonCameraTags $nikonCameraTags = null,
public ?OlympusCameraTags $olympusCameraTags = null,   // <-- NEW
public ?JfifSegment $jfifSegment = null,
```

Add accessor method after `nikonAviLookup()`:
```php
/**
 * Returns a typed lookup for Olympus AVI Camera Tags (JUNK chunk).
 */
public function olympusAviLookup(): OlympusAviLookup
{
    return new OlympusAviLookup($this->olympusCameraTags);
}
```

Update the container support table in the class docblock.

- [ ] **Step 5: Wire in MetadataReader::fromRiff()**

Update the `->withRiff(...)` call to pass `$result->olympusCameraTags`:
```php
->withRiff($result->info, $result->aviHeader, $result->riffExif, $result->nikonCameraTags, $result->olympusCameraTags)
```

- [ ] **Step 6: Run full CI**

Run: `make test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Model/Riff/OlympusAviLookup.php tests/Model/Riff/OlympusAviLookupTest.php \
    src/Model/MetadataBuilder.php src/Model/Metadata.php src/MetadataReader.php
git commit -m "GH-2301: Wire OlympusCameraTags through MetadataBuilder and Metadata"
```

---

## Task 4: Factory Integration (Camera + Exposure + Temporal)

**Files:**
- Modify: `src/Factory/Structured/CameraFactory.php`
- Modify: `src/Factory/Structured/ExposureFactory.php`
- Modify: `src/Factory/Structured/TemporalFactory.php`

- [ ] **Step 1: CameraFactory — add Olympus make/model fallback**

In `src/Factory/Structured/CameraFactory.php`, add `$olympusAvi = $metadata->olympusAviLookup();` alongside the existing `$nikonAvi`.

Append to the `make` chain: `?? $olympusAvi->make()`
Append to the `model` chain: `?? $olympusAvi->model()`

These go AFTER the Nikon fallback (at the very end of each chain).

Commit: `GH-2301: Add Olympus AVI make/model fallback in CameraFactory`

- [ ] **Step 2: ExposureFactory — add Olympus fNumber fallback**

In `src/Factory/Structured/ExposureFactory.php`, add `$olympusAvi = $metadata->olympusAviLookup();`

Append to the `fNumber:` chain: `?? $olympusAvi->fNumber()`

This goes AFTER the Nikon fNumber fallback.

Commit: `GH-2301: Add Olympus AVI fNumber fallback in ExposureFactory`

- [ ] **Step 3: TemporalFactory — add Olympus DateTime1/DateTime2 fallback**

In `src/Factory/Structured/TemporalFactory.php`:

Add import: `use MagicSunday\ImageMeta\Model\Riff\OlympusAviLookup;`

In `create()`, add: `$olympusAviLookup = $metadata->olympusAviLookup();`

Update `buildTemporal()` call and signature to pass `?OlympusAviLookup $olympusAviLookup = null`.

Insert Olympus fallbacks after the Nikon fallbacks and before the generic RIFF fallback:
```php
// Olympus AVI JUNK chunk date fallback —
// DateTime1 is the capture date, DateTime2 is typically identical.
// Placed before generic RIFF because the Olympus-specific fields are more reliable
// than free-form RIFF INFO strings.
$create ??= DateTimeUtil::parseRiffDate($olympusAviLookup?->dateTime1());
```

And after `$originalWithTz ??= DateTimeUtil::parseRiffDate($nikonAviLookup?->dateTimeOriginal());`:
```php
$originalWithTz ??= DateTimeUtil::parseRiffDate($olympusAviLookup?->dateTime1());
```

Commit: `GH-2301: Add Olympus AVI date fallback in TemporalFactory`

- [ ] **Step 4: Run full CI**

Run: `make test`
Expected: PASS

---

## Task 5: Format Script Olympus Section

**Files:**
- Modify: `scripts/imagemeta-format.php`

- [ ] **Step 1: Add Olympus tag label map**

```php
/**
 * Maps Olympus AVI JUNK field byte offsets to human-readable labels.
 *
 * Reference: ExifTool Olympus.pm — %Image::ExifTool::Olympus::AVI.
 *
 * @var array<int, string>
 */
private const array OLYMPUS_AVI_TAG_LABELS = [
    0x0012 => 'Make',
    0x002C => 'Camera Model Name',
    0x005E => 'F Number',
    0x0083 => 'Date Time 1',
    0x009D => 'Date Time 2',
];
```

- [ ] **Step 2: Add Olympus context to getTagName()**

```php
if ($ifdContext === 'Olympus') {
    return self::OLYMPUS_AVI_TAG_LABELS[$tagId] ?? sprintf('Olympus AVI 0x%04x', $tagId);
}
```

- [ ] **Step 3: Add printOlympusSection() method**

```php
/**
 * Prints Olympus Camera Tags (JUNK chunk) section with byte offsets.
 */
private function printOlympusSection(Metadata $metadata): void
{
    if (!$metadata->olympusCameraTags instanceof OlympusCameraTags) {
        return;
    }

    $data = $metadata->olympusCameraTags->entries;
    ksort($data);

    if ($data !== []) {
        $this->printSection('Olympus', $data, showHex: true, ifdContext: 'Olympus');
    }
}
```

Add import: `use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;`

- [ ] **Step 4: Call printOlympusSection() after printNikonSection()**

```php
$this->printOlympusSection($metadata);
```

- [ ] **Step 5: Run full CI, verify output**

Run: `make test`
Then verify: `docker compose run --rm buildbox php scripts/imagemeta-format.php /volume1/Fotos/Eric/Kita/Videos/2014-06-06_11-08-45-000.avi`

Expected `---- Olympus ----` section with hex offsets.

- [ ] **Step 6: Commit**

```bash
git add scripts/imagemeta-format.php
git commit -m "GH-2301: Add Olympus section with byte offsets to format script"
```

---

## Task 6: CGL/Rector Fixes + CI Verification

- [ ] **Step 1: Run CGL and Rector iteratively**

```bash
make rector && make cgl && make rector-check && make cgl-check
```

- [ ] **Step 2: Commit CGL/Rector fixes separately (if any)**

```bash
git add -A
git commit -m "GH-2301: Apply CGL and Rector alignment fixes"
```

- [ ] **Step 3: Run full CI**

Run: `make test`
Expected: PASS

- [ ] **Step 4: Verify StructuredMetadata propagation**

```bash
docker compose run --rm buildbox php -r "
require '/app/.build/vendor/autoload.php';
use MagicSunday\ImageMeta\MetadataReader;
\$reader = MetadataReader::createDefault();
\$meta = \$reader->read('/volume1/Fotos/Eric/Kita/Videos/2014-06-06_11-08-45-000.avi');
\$s = \$meta->structured();
echo 'camera->make: ' . (\$s->hardware->camera?->make ?? 'NULL') . PHP_EOL;
echo 'camera->model: ' . (\$s->hardware->camera?->model ?? 'NULL') . PHP_EOL;
echo 'exposure->fNumber: ' . (\$s->settings->exposure?->settings?->fNumber ?? 'NULL') . PHP_EOL;
echo 'temporal->original: ' . (\$s->locationTime->temporal?->original?->format('Y-m-d H:i:s') ?? 'NULL') . PHP_EOL;
"
```

Expected:
- `camera->make: OLYMPUS DIGITAL CAMERA`
- `camera->model: D4488`
- `exposure->fNumber: 3.0`
- `temporal->original: 2014-06-06 11:08:45`

---

## Dependency Graph

```
Task 1 (Model + Parser) ──> Task 2 (RiffParser) ──> Task 3 (Wiring) ──┐
                                                                       ├──> Task 4 (Factories)
                                                                       ├──> Task 5 (Format)
                                                                       └──> Task 6 (CI)
```

Tasks 4 and 5 are independent after Task 3. Task 6 must be last.
