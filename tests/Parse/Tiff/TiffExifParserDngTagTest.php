<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Verifies parsing and exposure of baseline DNG tags from TIFF/EXIF payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(DngTag::class)]
final class TiffExifParserDngTagTest extends TestCase
{
    /**
     * Parses DNG core tags from IFD0 and exposes them through ParsedExif accessors.
     *
     * @return void
     */
    #[Test]
    public function parsesCoreDngTagsFromIfd0(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildClassicDngTiff());

        self::assertSame('1.7.1.0', $parsed->dngVersion());
        self::assertSame('1.4.0.0', $parsed->dngBackwardVersion());
        self::assertSame('MagicSunday Camera', $parsed->uniqueCameraModel());
    }

    /**
     * Returns null for DNG accessors when corresponding tags are not present.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenCoreDngTagsAreMissing(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 0)
            . pack('V', 0),
        );

        self::assertNull($parsed->dngVersion());
        self::assertNull($parsed->dngBackwardVersion());
        self::assertNull($parsed->uniqueCameraModel());
    }

    /**
     * Builds a classic TIFF payload with required baseline DNG tags in IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, pp. 24-25) defines DNGVersion and
     * DNGBackwardVersion as BYTE[4], and UniqueCameraModel as ASCII.
     */
    private function buildClassicDngTiff(): string
    {
        $ifdOffset         = 8;
        $entryCount        = 3;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'MagicSunday Camera');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00010701)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00000401)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', 0)
            . $uniqueCameraModel;
    }
}
