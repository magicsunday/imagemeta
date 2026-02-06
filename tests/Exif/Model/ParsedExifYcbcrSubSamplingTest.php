<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises YCbCrSubSampling value enforcement in ParsedExif.
 * It validates that only the two values defined by EXIF 3.0 §4.6.5.1.12 are accepted.
 * The suite covers valid pairs ([2,1] and [2,2]) and rejects non-conformant values.
 * This ensures chroma subsampling metadata is spec-compliant.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifYcbcrSubSamplingTest extends TestCase
{
    /**
     * Supplies [2,1] representing YCbCr 4:2:2.
     * Confirms ycbcrSubSampling() returns the valid pair.
     *
     * @return void
     */
    #[Test]
    public function acceptsFourTwoTwo(): void
    {
        $parsedExif = $this->parsedExifWithYcbcr([2, 1]);

        self::assertSame([2, 1], $parsedExif->ycbcrSubSampling());
    }

    /**
     * Supplies [2,2] representing YCbCr 4:2:0.
     * Confirms ycbcrSubSampling() returns the valid pair.
     *
     * @return void
     */
    #[Test]
    public function acceptsFourTwoZero(): void
    {
        $parsedExif = $this->parsedExifWithYcbcr([2, 2]);

        self::assertSame([2, 2], $parsedExif->ycbcrSubSampling());
    }

    /**
     * Supplies [1,1] which is not defined in EXIF 3.0 §4.6.5.1.12.
     * Confirms ycbcrSubSampling() rejects the non-conformant pair.
     *
     * @return void
     */
    #[Test]
    public function rejectsOneOne(): void
    {
        $parsedExif = $this->parsedExifWithYcbcr([1, 1]);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * Supplies [4,4] which is not defined in EXIF 3.0 §4.6.5.1.12.
     * Confirms ycbcrSubSampling() rejects the non-conformant pair.
     *
     * @return void
     */
    #[Test]
    public function rejectsFourFour(): void
    {
        $parsedExif = $this->parsedExifWithYcbcr([4, 4]);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * Supplies [2,4] which has a valid horizontal factor but invalid vertical.
     * Confirms ycbcrSubSampling() rejects the non-conformant pair.
     *
     * @return void
     */
    #[Test]
    public function rejectsTwoFour(): void
    {
        $parsedExif = $this->parsedExifWithYcbcr([2, 4]);

        self::assertNull($parsedExif->ycbcrSubSampling());
    }

    /**
     * @param list<int> $values
     */
    private function parsedExifWithYcbcr(array $values): ParsedExif
    {
        $ifd0 = new Ifd([
            ExifTag::YCBCR_SUB_SAMPLING => new IfdEntry(
                ExifTag::YCBCR_SUB_SAMPLING,
                3,
                2,
                $values,
            ),
        ]);

        return new ParsedExif($ifd0, new Ifd([]), null, null, null);
    }
}
