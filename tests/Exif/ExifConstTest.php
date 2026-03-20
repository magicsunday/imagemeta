<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif;

use MagicSunday\ImageMeta\Exif\ExifConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the shared EXIF constant values match their Parse-layer originals.
 *
 * @internal
 */
#[CoversClass(ExifConst::class)]
#[UsesClass(TiffConst::class)]
final class ExifConstTest extends TestCase
{
    /**
     * EXIF 3.0 §4.6.6.8 — sentinel denominator must match 0xFFFFFFFF.
     */
    #[Test]
    public function unknownDenominatorMatchesExpectedValue(): void
    {
        $ref   = new ReflectionClass(ExifConst::class);
        $value = $ref->getConstant('EXIF_UNKNOWN_DENOMINATOR');

        self::assertSame(0xFFFFFFFF, $value);
    }

    /**
     * TIFF 6.0 §2.2 — ASCII field type must match the Parse-layer constant.
     */
    #[Test]
    public function typeAsciiMatchesParseLayerValue(): void
    {
        $ref   = new ReflectionClass(ExifConst::class);
        $value = $ref->getConstant('TYPE_ASCII');

        self::assertSame(TiffConst::TYPE_ASCII, $value);
    }
}
