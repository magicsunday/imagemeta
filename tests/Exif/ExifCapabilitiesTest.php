<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif;

use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ExifCapabilities mapping from raw EXIF version strings.
 * It verifies handling of numeric, decimal, and malformed versions.
 * The suite checks that unknown or empty inputs map to the fallback profile.
 * This keeps capability resolution consistent for downstream parsing logic.
 */
#[CoversClass(ExifCapabilities::class)]
final class ExifCapabilitiesTest extends TestCase
{
    /**
     * Maps raw EXIF version strings to capability profiles.
     * The data provider covers nulls, decimal formats, and raw four-digit codes.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('exifVersionProvider')]
    public function mapsExifVersionToCapabilityProfile(string $expected, ?string $input): void
    {
        self::assertSame($expected, ExifCapabilities::fromVersion($input));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function exifVersionProvider(): iterable
    {
        yield 'null yields unknown profile' => ['unknown', null];
        yield 'empty string yields unknown profile' => ['unknown', ''];
        yield 'decimal 1.0 maps to 1.0' => ['1.0', '1.0'];
        yield 'decimal 1.1 maps to 1.1' => ['1.1', '1.1'];
        yield 'numeric 0200 maps to 2.0' => ['2.0', '0200'];
        yield 'decimal 2.0 maps to 2.0' => ['2.0', '2.0'];
        yield 'decimal 2.00 maps to 2.0' => ['2.0', '2.00'];
        yield 'numeric 0210 maps to 2.1' => ['2.1', '0210'];
        yield 'decimal 2.1 maps to 2.1' => ['2.1', '2.1'];
        yield 'decimal 2.2 maps to 2.2' => ['2.2', '2.2'];
        yield 'raw 0220 maps to 2.2' => ['2.2', '0220'];
        yield 'raw 0221 maps to 2.21' => ['2.21', '0221'];
        yield 'decimal 2.21 maps to 2.21' => ['2.21', '2.21'];
        yield 'raw 0230 stays grouped as 2.3' => ['2.3', '0230'];
        yield 'decimal 2.3 maps to 2.3' => ['2.3', '2.3'];
        yield 'raw 0231 maps to 2.31' => ['2.31', '0231'];
        yield 'decimal 2.31 maps to 2.31' => ['2.31', '2.31'];
        yield 'raw 0232 maps to 2.32' => ['2.32', '0232'];
        yield 'decimal 2.32 maps to 2.32' => ['2.32', '2.32'];
        yield 'numeric 0300 maps to 3.0' => ['3.0', '0300'];
        yield 'decimal 3.0 maps to 3.0' => ['3.0', '3.0'];
        yield 'null bytes are rejected' => ['unknown', "0300\0"];
        yield 'unknown when digits do not match revision' => ['unknown', '9999'];
        yield 'unknown when format malformed' => ['unknown', 'abc'];
    }
}
