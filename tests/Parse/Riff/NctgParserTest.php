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
use MagicSunday\ImageMeta\Model\Riff\NikonCameraTags;
use MagicSunday\ImageMeta\Parse\Riff\NctgParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;

#[CoversClass(NctgParser::class)]
#[UsesClass(NikonCameraTags::class)]
#[UsesClass(Unpack::class)]
final class NctgParserTest extends TestCase
{
    #[Test]
    public function parsesStringTag(): void
    {
        // Tag 0x0003 (Make), size=6, "NIKON\0"
        $payload = pack('v', 0x0003) . pack('v', 6) . "NIKON\x00";

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertSame('NIKON', $result->make);
        self::assertSame('NIKON', $result->entries[0x0003]);
    }

    #[Test]
    public function parsesRationalTag(): void
    {
        // Tag 0x0009 (FNumber), size=8, 28/10 = 2.8
        $payload = pack('v', 0x0009) . pack('v', 8) . pack('V', 28) . pack('V', 10);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertEqualsWithDelta(2.8, $result->fNumber, 0.001);
        self::assertSame('2.8', $result->entries[0x0009]);
    }

    #[Test]
    public function parsesDateTimeOriginalTag(): void
    {
        // Tag 0x0013 (DateTimeOriginal), size=20
        $dateStr = "2009:12:25 00:15:52\x00";
        $payload = pack('v', 0x0013) . pack('v', 20) . $dateStr;

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertSame('2009:12:25 00:15:52', $result->dateTimeOriginal);
    }

    #[Test]
    public function parsesShortTag(): void
    {
        // Tag 0x0007 (Orientation), size=2, value=1
        $payload = pack('v', 0x0007) . pack('v', 2) . pack('v', 1);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertSame(1, $result->orientation);
    }

    #[Test]
    public function parsesMultipleTags(): void
    {
        $payload = '';
        // Make
        $payload .= pack('v', 0x0003) . pack('v', 6) . "NIKON\x00";
        // Model
        $payload .= pack('v', 0x0004) . pack('v', 4) . "P80\x00";
        // FNumber 2.8
        $payload .= pack('v', 0x0009) . pack('v', 8) . pack('V', 28) . pack('V', 10);
        // DateTimeOriginal
        $dateStr = "2009:12:25 00:15:52\x00";
        $payload .= pack('v', 0x0013) . pack('v', 20) . $dateStr;

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertSame('NIKON', $result->make);
        self::assertSame('P80', $result->model);
        self::assertEqualsWithDelta(2.8, $result->fNumber, 0.001);
        self::assertSame('2009:12:25 00:15:52', $result->dateTimeOriginal);
        self::assertCount(4, $result->entries);
    }

    #[Test]
    public function preservesUnknownTagsInEntries(): void
    {
        // Tag 0x000d (unknown), size=2, value=0
        $payload = pack('v', 0x000D) . pack('v', 2) . pack('v', 0);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertArrayHasKey(0x000D, $result->entries);
        self::assertSame('0', $result->entries[0x000D]);
    }

    #[Test]
    public function returnsNullOnEmptyPayload(): void
    {
        $result = (new NctgParser())->parse('');

        self::assertNull($result);
    }

    #[Test]
    public function toleratesTruncatedTrailingTag(): void
    {
        // Valid first tag, then truncated second tag (only 2 bytes)
        $payload = pack('v', 0x0003) . pack('v', 6) . "NIKON\x00";
        $payload .= pack('v', 0x0004); // truncated — no size field

        $result = (new NctgParser())->parse($payload);

        // Postel's Law: parse what we can
        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertSame('NIKON', $result->make);
    }

    #[Test]
    public function skipsTagWithZeroDenominatorRational(): void
    {
        // Tag 0x0009 (FNumber), size=8, 28/0 — division by zero
        $payload = pack('v', 0x0009) . pack('v', 8) . pack('V', 28) . pack('V', 0);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertNull($result->fNumber);
    }

    #[Test]
    public function parsesExposureTimeRational(): void
    {
        // Tag 0x0008 (ExposureTime), size=8, 10/299
        $payload = pack('v', 0x0008) . pack('v', 8) . pack('V', 10) . pack('V', 299);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertEqualsWithDelta(10.0 / 299.0, $result->exposureTime, 0.0001);
    }

    #[Test]
    public function parsesSignedExposureCompensation(): void
    {
        // Tag 0x000a (ExposureCompensation), size=8, -10/10 = -1.0 EV
        // Signed: 0xFFFFFFF6 = -10 as signed i32
        $payload = pack('v', 0x000A) . pack('v', 8) . pack('V', 0xFFFFFFF6) . pack('V', 10);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertEqualsWithDelta(-1.0, $result->exposureCompensation, 0.001);
    }

    #[Test]
    public function returnsNullForTagWithZeroSize(): void
    {
        // Tag 0x0003 (Make), size=0 — empty value produces no entries
        $payload = pack('v', 0x0003) . pack('v', 0);

        $result = (new NctgParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function preservesHighBitTagIdInEntries(): void
    {
        // Tag 0x801a (high bit set, unknown), size=4, value=0
        $payload = pack('v', 0x801A) . pack('v', 4) . pack('V', 0);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertArrayHasKey(0x801A, $result->entries);
        self::assertSame('0', $result->entries[0x801A]);
    }

    #[Test]
    public function preservesUnknownRationalTagInEntries(): void
    {
        // Tag 0x001c (unknown), size=8, rational 7836/1000 = 7.836
        $payload = pack('v', 0x001C) . pack('v', 8) . pack('V', 7836) . pack('V', 1000);

        $result = (new NctgParser())->parse($payload);

        self::assertInstanceOf(NikonCameraTags::class, $result);
        self::assertArrayHasKey(0x001C, $result->entries);
        self::assertSame('7.836', $result->entries[0x001C]);
    }
}
