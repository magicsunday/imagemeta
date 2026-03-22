<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Validates EXIF string sanitization and EXIF version byte decoding.
 * It verifies null-byte stripping, whitespace trimming, and known version normalization.
 *
 * @internal
 */
#[CoversClass(StringConverter::class)]
#[UsesClass(ExifRational::class)]
final class StringConverterTest extends TestCase
{
    private StringConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new StringConverter();
    }

    /**
     * Sanitizes a clean string without modification.
     */
    #[Test]
    public function sanitizeReturnsCleanString(): void
    {
        self::assertSame('Canon EOS R5', $this->converter->sanitize('Canon EOS R5'));
    }

    /**
     * Strips null bytes and trims whitespace from EXIF strings.
     */
    #[Test]
    public function sanitizeStripsNullBytesAndWhitespace(): void
    {
        self::assertSame('Canon', $this->converter->sanitize("Canon\0\0  "));
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function sanitizeReturnsNullForNull(): void
    {
        self::assertNull($this->converter->sanitize(null));
    }

    /**
     * Returns null for an empty string.
     */
    #[Test]
    public function sanitizeReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->sanitize(''));
    }

    /**
     * Returns null for a string containing only null bytes and whitespace.
     */
    #[Test]
    public function sanitizeReturnsNullForOnlyNullBytes(): void
    {
        self::assertNull($this->converter->sanitize("\0\0\0"));
    }

    /**
     * Returns null for non-string types.
     */
    #[Test]
    public function sanitizeReturnsNullForNonString(): void
    {
        self::assertNull($this->converter->sanitize(42));
        self::assertNull($this->converter->sanitize(3.14));
        self::assertNull($this->converter->sanitize(new ExifRational(1, 1)));
    }

    /**
     * Converts known EXIF version bytes to dotted decimal format.
     *
     * @param string $expected Expected dotted version string.
     */
    #[Test]
    #[DataProvider('provideKnownExifVersions')]
    public function toExifVersionConvertsKnownVersions(string $bytes, string $expected): void
    {
        self::assertSame($expected, $this->converter->toExifVersion($bytes));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideKnownExifVersions(): iterable
    {
        yield 'EXIF 1.0' => ['0100', '1.00'];
        yield 'EXIF 2.0' => ['0200', '2.00'];
        yield 'EXIF 2.1' => ['0210', '2.10'];
        yield 'EXIF 2.2' => ['0220', '2.20'];
        yield 'EXIF 2.21' => ['0221', '2.21'];
        yield 'EXIF 2.3' => ['0230', '2.30'];
        yield 'EXIF 2.31' => ['0231', '2.31'];
        yield 'EXIF 2.32' => ['0232', '2.32'];
        yield 'EXIF 3.0' => ['0300', '3.00'];
    }

    /**
     * Returns null for null input.
     */
    #[Test]
    public function toExifVersionReturnsNullForNull(): void
    {
        self::assertNull($this->converter->toExifVersion(null));
    }

    /**
     * Returns null for empty string.
     */
    #[Test]
    public function toExifVersionReturnsNullForEmptyString(): void
    {
        self::assertNull($this->converter->toExifVersion(''));
    }

    /**
     * Rejects version strings with null bytes.
     */
    #[Test]
    public function toExifVersionRejectsNullBytes(): void
    {
        self::assertNull($this->converter->toExifVersion("0300\0"));
    }

    /**
     * Rejects version strings that are not exactly 4 characters.
     */
    #[Test]
    public function toExifVersionRejectsWrongLength(): void
    {
        self::assertNull($this->converter->toExifVersion('030'));
        self::assertNull($this->converter->toExifVersion('03000'));
    }

    /**
     * Rejects version strings containing non-digit characters.
     */
    #[Test]
    public function toExifVersionRejectsNonDigits(): void
    {
        self::assertNull($this->converter->toExifVersion('0x30'));
    }

    /**
     * Rejects unknown version numbers that are not in the known list.
     */
    #[Test]
    public function toExifVersionRejectsUnknownVersion(): void
    {
        self::assertNull($this->converter->toExifVersion('9999'));
    }
}
