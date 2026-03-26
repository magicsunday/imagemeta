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
use function str_repeat;
use function strlen;
use function substr_replace;

#[CoversClass(OlympusJunkParser::class)]
#[UsesClass(OlympusCameraTags::class)]
#[UsesClass(Unpack::class)]
final class OlympusJunkParserTest extends TestCase
{
    /**
     * Builds a minimal 0xB5-byte Olympus JUNK payload with the signature
     * and optional field values placed at correct offsets.
     *
     * @param string|null $make      24-byte string at offset 0x0012
     * @param string|null $model     24-byte string at offset 0x002C
     * @param int|null    $fNumNum   Numerator for rational64u at offset 0x005E
     * @param int|null    $fNumDen   Denominator for rational64u at offset 0x005E
     * @param string|null $dateTime1 24-byte string at offset 0x0083
     * @param string|null $dateTime2 24-byte string at offset 0x009D
     */
    private function buildMinimalOlympusPayload(
        ?string $make = null,
        ?string $model = null,
        ?int $fNumNum = null,
        ?int $fNumDen = null,
        ?string $dateTime1 = null,
        ?string $dateTime2 = null,
    ): string {
        // Start with 0xB5 bytes of zeros
        $payload = str_repeat("\x00", OlympusJunkParser::MIN_PAYLOAD_SIZE);

        // Write signature at offset 0
        $sig     = OlympusJunkParser::SIGNATURE;
        $payload = substr_replace($payload, $sig, 0, strlen($sig));

        // Write fields at their fixed offsets (24-byte wide, null-padded)
        if ($make !== null) {
            $payload = substr_replace($payload, str_pad($make, 24, "\x00"), 0x0012, 24);
        }

        if ($model !== null) {
            $payload = substr_replace($payload, str_pad($model, 24, "\x00"), 0x002C, 24);
        }

        if ($fNumNum !== null && $fNumDen !== null) {
            $payload = substr_replace($payload, pack('VV', $fNumNum, $fNumDen), 0x005E, 8);
        }

        if ($dateTime1 !== null) {
            $payload = substr_replace($payload, str_pad($dateTime1, 24, "\x00"), 0x0083, 24);
        }

        if ($dateTime2 !== null) {
            $payload = substr_replace($payload, str_pad($dateTime2, 24, "\x00"), 0x009D, 24);
        }

        return $payload;
    }

    #[Test]
    public function parsesOlympusMakeFromJunkPayload(): void
    {
        $payload = $this->buildMinimalOlympusPayload(make: 'OLYMPUS');
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('OLYMPUS', $result->make);
    }

    #[Test]
    public function parsesOlympusModelFromJunkPayload(): void
    {
        $payload = $this->buildMinimalOlympusPayload(model: 'FE120,X700');
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('FE120,X700', $result->model);
    }

    #[Test]
    public function parsesFNumberRational(): void
    {
        $payload = $this->buildMinimalOlympusPayload(fNumNum: 28, fNumDen: 10);
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertNotNull($result->fNumber);
        self::assertEqualsWithDelta(2.8, $result->fNumber, 0.001);
        self::assertSame('2.8', $result->entries[0x005E]);
    }

    #[Test]
    public function parsesDateTime1InCtimeFormat(): void
    {
        $payload = $this->buildMinimalOlympusPayload(dateTime1: 'Wed Jan 07 20:37:36 2009');
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Wed Jan 07 20:37:36 2009', $result->dateTime1);
    }

    #[Test]
    public function parsesDateTime2(): void
    {
        $payload = $this->buildMinimalOlympusPayload(dateTime2: 'Wed Jan 07 20:37:36 2009');
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Wed Jan 07 20:37:36 2009', $result->dateTime2);
    }

    #[Test]
    public function parsesAllFieldsTogether(): void
    {
        $payload = $this->buildMinimalOlympusPayload(
            make: 'OLYMPUS',
            model: 'FE120,X700',
            fNumNum: 28,
            fNumDen: 10,
            dateTime1: 'Wed Jan 07 20:37:36 2009',
            dateTime2: 'Wed Jan 07 20:37:36 2009',
        );
        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('OLYMPUS', $result->make);
        self::assertSame('FE120,X700', $result->model);
        self::assertEqualsWithDelta(2.8, $result->fNumber, 0.001);
        self::assertSame('Wed Jan 07 20:37:36 2009', $result->dateTime1);
        self::assertSame('Wed Jan 07 20:37:36 2009', $result->dateTime2);
        self::assertCount(5, $result->entries);
    }

    #[Test]
    public function returnsNullOnInvalidSignature(): void
    {
        $payload = str_repeat("\x00", OlympusJunkParser::MIN_PAYLOAD_SIZE);
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullOnTruncatedPayload(): void
    {
        // Shorter than MIN_PAYLOAD_SIZE
        $payload = OlympusJunkParser::SIGNATURE . str_repeat("\x00", 10);
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullWhenAllFieldsEmpty(): void
    {
        // Signature present but all fields are null bytes
        $payload = $this->buildMinimalOlympusPayload();
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertNull($result);
    }

    #[Test]
    public function skipsFNumberWithZeroDenominator(): void
    {
        $payload = $this->buildMinimalOlympusPayload(
            make: 'OLYMPUS',
            fNumNum: 28,
            fNumDen: 0,
        );
        $result = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertNull($result->fNumber);
        self::assertArrayNotHasKey(0x005E, $result->entries);
    }

    #[Test]
    public function handlesNonStandardDayAbbreviation(): void
    {
        // Some Olympus firmware uses non-standard day abbreviations (e.g. "Wen" instead of "Wed")
        $payload = $this->buildMinimalOlympusPayload(dateTime1: 'Wen Jan 07 20:37:36 2009');
        $result  = (new OlympusJunkParser())->parse($payload);

        self::assertInstanceOf(OlympusCameraTags::class, $result);
        self::assertSame('Wen Jan 07 20:37:36 2009', $result->dateTime1);
    }
}
