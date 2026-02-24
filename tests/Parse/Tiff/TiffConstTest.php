<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

/**
 * Verifies TIFF constants remain aligned with the specification-defined numeric values.
 * It checks magic numbers for classic TIFF and BigTIFF alongside type identifiers.
 * The data provider enumerates all public constants to prevent silent drift.
 * This guards parsers that rely on these values for type and header validation.
 */
#[CoversClass(TiffConst::class)]
final class TiffConstTest extends TestCase
{
    /**
     * Provides each public constant defined by {@see TiffConst} alongside its expected numeric value.
     * The dataset tracks TIFF magic numbers and type identifiers used across parsers.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function constantProvider(): iterable
    {
        yield 'magic classic' => ['MAGIC_CLASSIC', 0x002A];
        yield 'magic big' => ['MAGIC_BIG', 0x002B];
        yield 'type byte' => ['TYPE_BYTE', 1];
        yield 'type ascii' => ['TYPE_ASCII', 2];
        yield 'type short' => ['TYPE_SHORT', 3];
        yield 'type long' => ['TYPE_LONG', 4];
        yield 'type rational' => ['TYPE_RATIONAL', 5];
        yield 'type sbyte' => ['TYPE_SBYTE', 6];
        yield 'type undefined' => ['TYPE_UNDEFINED', 7];
        yield 'type sshort' => ['TYPE_SSHORT', 8];
        yield 'type slong' => ['TYPE_SLONG', 9];
        yield 'type srational' => ['TYPE_SRATIONAL', 10];
        yield 'type float' => ['TYPE_FLOAT', 11];
        yield 'type double' => ['TYPE_DOUBLE', 12];
        yield 'type ifd' => ['TYPE_IFD', 13];
        yield 'type long8' => ['TYPE_LONG8', 16];
        yield 'type slong8' => ['TYPE_SLONG8', 17];
        yield 'type ifd8' => ['TYPE_IFD8', 18];
    }

    /**
     * Compares each constant against its expected numeric value.
     * This guards against accidental changes to TIFF type identifiers.
     */
    #[Test]
    #[DataProvider('constantProvider')]
    public function itExposesExpectedConstantValues(string $constantName, int $expectedValue): void
    {
        $reflection  = new ReflectionClass(TiffConst::class);
        $actualValue = $reflection->getConstant($constantName);

        self::assertSame(
            $expectedValue,
            $actualValue,
            sprintf('TiffConst::%s must remain %d.', $constantName, $expectedValue)
        );
    }
}
