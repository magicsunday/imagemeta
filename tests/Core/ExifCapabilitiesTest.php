<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ExifCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Core\ExifCapabilities
 */
final class ExifCapabilitiesTest extends TestCase
{
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
        yield 'null defaults to 2.2' => ['2.2', null];
        yield 'empty string defaults to 2.2' => ['2.2', ''];
        yield 'raw 0100 maps to 1.0' => ['1.0', '0100'];
        yield 'raw 0110 maps to 1.1' => ['1.1', '0110'];
        yield 'decimal 1.00 maps to 1.0' => ['1.0', '1.00'];
        yield 'raw 0200 maps to 2.0' => ['2.0', '0200'];
        yield 'decimal 2.00 maps to 2.0' => ['2.0', '2.00'];
        yield 'raw 0221 maps to 2.21' => ['2.21', '0221'];
        yield 'decimal 2.21 maps to 2.21' => ['2.21', '2.21'];
        yield 'raw 0231 maps to 2.31' => ['2.31', '0231'];
        yield 'decimal 2.31 maps to 2.31' => ['2.31', '2.31'];
        yield 'raw 0232 maps to 2.32' => ['2.32', '0232'];
        yield 'decimal 2.32 maps to 2.32' => ['2.32', '2.32'];
        yield 'raw 0230 stays grouped as 2.3' => ['2.3', '0230'];
        yield 'raw 0300 maps to 3.0' => ['3.0', '0300'];
        yield 'decimal 3.0 maps to 3.0' => ['3.0', '3.0'];
    }
}
