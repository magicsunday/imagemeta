<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\FlashPix;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Parse\FlashPix\OlePropertySet;
use MagicSunday\ImageMeta\Parse\FlashPix\OlePropertySetParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;
use function strlen;

/**
 * Exercises OLE property set parsing with synthetic binary payloads.
 * Verifies header validation, section parsing, and typed value extraction.
 */
#[CoversClass(OlePropertySetParser::class)]
#[UsesClass(OlePropertySet::class)]
final class OlePropertySetParserTest extends TestCase
{
    #[Test]
    public function parseReturnsPropertySetFromValidHeader(): void
    {
        $raw    = $this->buildPropertySet(1252, [2 => ['type' => 0x001E, 'value' => 'Title']]);
        $parser = new OlePropertySetParser();
        $result = $parser->parse($raw);

        self::assertInstanceOf(OlePropertySet::class, $result);
        self::assertSame(1252, $result->codepage);
        self::assertSame('Title', $result->property(2));
    }

    #[Test]
    public function parseReturnsNullForInvalidByteOrder(): void
    {
        $raw    = "\x00\x00" . str_repeat("\x00", 46);
        $parser = new OlePropertySetParser();

        self::assertNull($parser->parse($raw));
    }

    #[Test]
    public function parseReturnsNullForTruncatedInput(): void
    {
        $parser = new OlePropertySetParser();

        self::assertNull($parser->parse('short'));
    }

    #[Test]
    public function parsesLpstrProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [2 => ['type' => 0x001E, 'value' => 'ASCII Title']]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertSame('ASCII Title', $result?->property(2));
    }

    #[Test]
    public function parsesLpwstrProperty(): void
    {
        $raw    = $this->buildPropertySet(1200, [2 => ['type' => 0x001F, 'value' => 'Unicode Title']]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertSame('Unicode Title', $result?->property(2));
    }

    #[Test]
    public function parsesLongProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [3 => ['type' => 0x0003, 'value' => 42]]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertSame(42, $result?->property(3));
    }

    #[Test]
    public function parsesShortProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [3 => ['type' => 0x0002, 'value' => 7]]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertSame(7, $result?->property(3));
    }

    #[Test]
    public function parsesDoubleProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [3 => ['type' => 0x0005, 'value' => 3.14]]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertEqualsWithDelta(3.14, $result?->property(3), 0.001);
    }

    #[Test]
    public function parsesBooleanProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [3 => ['type' => 0x000B, 'value' => true]]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertTrue($result?->property(3));
    }

    #[Test]
    public function parsesFiletimeProperty(): void
    {
        $raw    = $this->buildPropertySet(1252, [12 => ['type' => 0x0040, 'value' => '2025-06-15T12:00:00']]);
        $result = (new OlePropertySetParser())->parse($raw);

        $dateTime = $result?->property(12);
        self::assertInstanceOf(DateTimeImmutable::class, $dateTime);
        self::assertSame('2025-06-15', $dateTime->format('Y-m-d'));
    }

    #[Test]
    public function parseReturnsNullForEmptySection(): void
    {
        $raw = $this->buildEmptyPropertySet();

        self::assertNull((new OlePropertySetParser())->parse($raw));
    }

    #[Test]
    public function parsesMultipleProperties(): void
    {
        $raw = $this->buildPropertySet(1252, [
            2 => ['type' => 0x001E, 'value' => 'Title'],
            4 => ['type' => 0x001E, 'value' => 'Author'],
            3 => ['type' => 0x0003, 'value' => 100],
        ]);
        $result = (new OlePropertySetParser())->parse($raw);

        self::assertInstanceOf(OlePropertySet::class, $result);
        self::assertSame('Title', $result->property(2));
        self::assertSame('Author', $result->property(4));
        self::assertSame(100, $result->property(3));
    }

    /**
     * Builds a complete OLE property set binary stream.
     *
     * @param int                                                               $codepage   Codepage for codepage property (PID 1).
     * @param array<int, array{type: int, value: string|int|float|bool|string}> $properties PID → type+value pairs.
     */
    private function buildPropertySet(int $codepage, array $properties): string
    {
        $properties[1] = ['type' => 0x0003, 'value' => $codepage];

        $propertyCount = count($properties);
        $pidTable      = '';
        $valueBlob     = '';
        $valueOffset   = 8 + ($propertyCount * 8);

        foreach ($properties as $pid => $prop) {
            $pidTable .= pack('V', $pid) . pack('V', $valueOffset);
            $encoded = $this->encodeValue($prop['type'], $prop['value']);

            $valueBlob .= $encoded;
            $valueOffset += strlen($encoded);
        }

        $sectionSize = 8 + ($propertyCount * 8) + strlen($valueBlob);
        $section     = pack('V', $sectionSize) . pack('V', $propertyCount) . $pidTable . $valueBlob;

        $sectionOffset = 28 + 20;

        $header = pack('v', 0xFFFE);
        $header .= pack('v', 0x0000);
        $header .= pack('V', 0x00020006);
        $header .= str_repeat("\x00", 16);
        $header .= pack('V', 1);

        $fmtEntry = str_repeat("\x00", 16);
        $fmtEntry .= pack('V', $sectionOffset);

        return $header . $fmtEntry . $section;
    }

    private function buildEmptyPropertySet(): string
    {
        $sectionOffset = 28 + 20;
        $section       = pack('V', 8) . pack('V', 0);

        $header = pack('v', 0xFFFE);
        $header .= pack('v', 0x0000);
        $header .= pack('V', 0x00020006);
        $header .= str_repeat("\x00", 16);
        $header .= pack('V', 1);

        $fmtEntry = str_repeat("\x00", 16);
        $fmtEntry .= pack('V', $sectionOffset);

        return $header . $fmtEntry . $section;
    }

    private function encodeValue(int $type, string|int|float|bool $value): string
    {
        $typePrefix = pack('V', $type);

        return match ($type) {
            0x0002  => $typePrefix . pack('v', (int) $value) . "\x00\x00",
            0x0003  => $typePrefix . pack('V', (int) $value),
            0x0004  => $typePrefix . pack('g', (float) $value),
            0x0005  => $typePrefix . pack('e', (float) $value),
            0x000B  => $typePrefix . pack('v', ((bool) $value) ? 0xFFFF : 0x0000) . "\x00\x00",
            0x001E  => $this->encodeLpstr($typePrefix, (string) $value),
            0x001F  => $this->encodeLpwstr($typePrefix, (string) $value),
            0x0040  => $this->encodeFiletime($typePrefix, (string) $value),
            default => $typePrefix,
        };
    }

    private function encodeLpstr(string $typePrefix, string $value): string
    {
        $bytes = $value . "\0";
        $size  = strlen($bytes);
        $pad   = (4 - ($size % 4)) % 4;

        return $typePrefix . pack('V', $size) . $bytes . str_repeat("\0", $pad);
    }

    private function encodeLpwstr(string $typePrefix, string $value): string
    {
        $utf16     = mb_convert_encoding($value . "\0", 'UTF-16LE', 'UTF-8');
        $charCount = (int) (strlen($utf16) / 2);

        return $typePrefix . pack('V', $charCount) . $utf16;
    }

    private function encodeFiletime(string $typePrefix, string $dateString): string
    {
        $dateTime  = new DateTimeImmutable($dateString, new DateTimeZone('UTC'));
        $unixMicro = (int) $dateTime->format('U') * 1_000_000 + (int) $dateTime->format('u');
        $filetime  = ($unixMicro * 10) + 116_444_736_000_000_000;
        $lo        = $filetime & 0xFFFFFFFF;
        $hi        = ($filetime >> 32) & 0xFFFFFFFF;

        return $typePrefix . pack('V', $lo) . pack('V', $hi);
    }
}
