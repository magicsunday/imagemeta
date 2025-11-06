<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Tiff;

use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TIFF 6.0 baseline tag constants.
 */
#[CoversClass(TiffTag::class)]
final class TiffTagTest extends TestCase
{
    /**
     * Verifies that key TIFF 6.0 tags are defined with correct values.
     */
    #[Test]
    public function tiff60BaselineTagsAreDefined(): void
    {
        self::assertSame(0x00FE, TiffTag::NEW_SUBFILE_TYPE);
        self::assertSame(0x00FF, TiffTag::SUBFILE_TYPE);
        self::assertSame(0x013D, TiffTag::PREDICTOR);
        self::assertSame(0x8773, TiffTag::ICC_PROFILE);
        self::assertSame(0x014A, TiffTag::SUB_IFDS);
    }

    /**
     * Verifies that TIFF tile-related tags are defined.
     */
    #[Test]
    public function tiffTileTagsAreDefined(): void
    {
        self::assertSame(0x0142, TiffTag::TILE_WIDTH);
        self::assertSame(0x0143, TiffTag::TILE_LENGTH);
        self::assertSame(0x0144, TiffTag::TILE_OFFSETS);
        self::assertSame(0x0145, TiffTag::TILE_BYTE_COUNTS);
    }

    /**
     * Verifies that TIFF/EP extension tags are defined.
     */
    #[Test]
    public function tiffEpTagsAreDefined(): void
    {
        self::assertSame(0x000B, TiffTag::PROCESSING_SOFTWARE);
        self::assertSame(0xA216, TiffTag::TIFF_EP_STANDARD_ID);
        self::assertSame(0x828F, TiffTag::BATTERY_LEVEL);
    }
}
