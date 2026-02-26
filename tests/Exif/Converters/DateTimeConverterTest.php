<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use DateTimeZone;
use MagicSunday\ImageMeta\Exif\Converters\DateTimeConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates EXIF date/time offset parsing and timezone conversion.
 * It verifies EXIF 3.0 §4.6.3 conformance for offset format handling.
 *
 * @internal
 */
#[CoversClass(DateTimeConverter::class)]
final class DateTimeConverterTest extends TestCase
{
    private DateTimeConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DateTimeConverter();
    }

    /**
     * Parses a positive UTC offset string into canonical form.
     */
    #[Test]
    public function parseOffsetStringReturnsPlusOffset(): void
    {
        self::assertSame('+05:30', $this->converter->parseOffsetString('+05:30'));
    }

    /**
     * Parses a negative UTC offset string into canonical form.
     */
    #[Test]
    public function parseOffsetStringReturnsMinusOffset(): void
    {
        self::assertSame('-08:00', $this->converter->parseOffsetString('-08:00'));
    }

    /**
     * Parses UTC zero offset.
     */
    #[Test]
    public function parseOffsetStringReturnsZeroOffset(): void
    {
        self::assertSame('+00:00', $this->converter->parseOffsetString('+00:00'));
    }

    /**
     * Returns null for non-string input.
     */
    #[Test]
    public function parseOffsetStringReturnsNullForIntInput(): void
    {
        self::assertNull($this->converter->parseOffsetString(530));
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function parseOffsetStringReturnsNullForNull(): void
    {
        self::assertNull($this->converter->parseOffsetString(null));
    }

    /**
     * Returns null for empty string.
     */
    #[Test]
    public function parseOffsetStringReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->parseOffsetString(''));
    }

    /**
     * Returns null for malformed offset string.
     */
    #[Test]
    public function parseOffsetStringReturnsNullForMalformedFormat(): void
    {
        self::assertNull($this->converter->parseOffsetString('05:30'));
    }

    /**
     * Rejects offset with minutes >= 60.
     */
    #[Test]
    public function parseOffsetStringRejectsMinutesAbove59(): void
    {
        self::assertNull($this->converter->parseOffsetString('+05:60'));
    }

    /**
     * Rejects offset above +14:00 maximum.
     */
    #[Test]
    public function parseOffsetStringRejectsAboveMaximum(): void
    {
        self::assertNull($this->converter->parseOffsetString('+14:01'));
        self::assertNull($this->converter->parseOffsetString('+15:00'));
    }

    /**
     * Accepts the maximum allowed offset +14:00.
     */
    #[Test]
    public function parseOffsetStringAcceptsMaximumOffset(): void
    {
        self::assertSame('+14:00', $this->converter->parseOffsetString('+14:00'));
    }

    /**
     * Parses a valid offset string into a DateTimeZone instance.
     */
    #[Test]
    public function parseOffsetReturnsDateTimeZone(): void
    {
        $zone = $this->converter->parseOffset('+05:30');

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('+05:30', $zone->getName());
    }

    /**
     * Returns null from parseOffset for null input.
     */
    #[Test]
    public function parseOffsetReturnsNullForNull(): void
    {
        self::assertNull($this->converter->parseOffset(null));
    }

    /**
     * Returns null from parseOffset for empty string.
     */
    #[Test]
    public function parseOffsetReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->parseOffset(''));
    }

    /**
     * Returns null from parseOffset for malformed format.
     */
    #[Test]
    public function parseOffsetReturnsNullForMalformedFormat(): void
    {
        self::assertNull($this->converter->parseOffset('05:30'));
    }

    /**
     * Rejects timezone offsets exceeding the +14:00 bound.
     */
    #[Test]
    public function parseOffsetRejectsAboveMaximum(): void
    {
        self::assertNull($this->converter->parseOffset('+14:01'));
    }

    /**
     * Converts valid offset to minutes.
     *
     * @param int $expectedMinutes Expected total minutes.
     */
    #[Test]
    #[DataProvider('provideOffsetMinutesValues')]
    public function offsetToMinutesReturnsCorrectValue(string $input, int $expectedMinutes): void
    {
        self::assertSame($expectedMinutes, $this->converter->offsetToMinutes($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function provideOffsetMinutesValues(): iterable
    {
        yield 'positive 5:30' => ['+05:30', 330];
        yield 'negative 8:00' => ['-08:00', -480];
        yield 'UTC zero' => ['+00:00', 0];
        yield 'positive 14:00' => ['+14:00', 840];
    }

    /**
     * Returns null from offsetToMinutes for null input.
     */
    #[Test]
    public function offsetToMinutesReturnsNullForNull(): void
    {
        self::assertNull($this->converter->offsetToMinutes(null));
    }

    /**
     * Returns null from offsetToMinutes for invalid format.
     */
    #[Test]
    public function offsetToMinutesReturnsNullForInvalidFormat(): void
    {
        self::assertNull($this->converter->offsetToMinutes('invalid'));
    }

    /**
     * Parses offset components into sign, hours, and minutes.
     */
    #[Test]
    public function parseOffsetComponentsReturnsComponents(): void
    {
        $result = $this->converter->parseOffsetComponents('-08:00');

        self::assertNotNull($result);
        self::assertSame(-1, $result['sign']);
        self::assertSame(8, $result['hours']);
        self::assertSame(0, $result['minutes']);
    }

    /**
     * Returns null components for null input.
     */
    #[Test]
    public function parseOffsetComponentsReturnsNullForNull(): void
    {
        self::assertNull($this->converter->parseOffsetComponents(null));
    }

    /**
     * Returns null components for whitespace-only string.
     */
    #[Test]
    public function parseOffsetComponentsReturnsNullForWhitespace(): void
    {
        self::assertNull($this->converter->parseOffsetComponents('   '));
    }
}
