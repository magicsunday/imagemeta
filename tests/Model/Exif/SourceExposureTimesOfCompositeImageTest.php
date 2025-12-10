<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type RationalPair = array{0:int,1:int}
 */
#[CoversClass(ParsedExif::class)]
final class SourceExposureTimesOfCompositeImageTest extends TestCase
{
    #[Test]
    public function decodesCompositeExposureMetadata(): void
    {
        $byteOrder = Endian::Little;
        $summary   = [
            [5, 1],
            [3, 1],
            [4, 1],
            [3, 1],
            [2, 1],
            [1, 2],
            [2, 1],
            [1, 3],
        ];

        $payload = $this->buildPayload($summary, $byteOrder, [
            [0.1, 0.2],
            [0.5],
        ]);

        $exifIfd = new Ifd([
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_UNDEFINED,
                strlen($payload),
                $payload,
            ),
        ]);

        $parsed = new ParsedExif(new Ifd([]), $exifIfd, null, null, null, byteOrder: $byteOrder);

        $result = $parsed->sourceExposureTimesOfCompositeImage();

        self::assertInstanceOf(SourceExposureTimes::class, $result);
        self::assertSame(5.0, $result->totalExposurePeriod);
        self::assertSame(3.0, $result->usedExposureTimeSum);
        self::assertSame(4.0, $result->allExposureTimeSum);
        self::assertSame(3.0, $result->sourceImageCount);
        self::assertSame(2.0, $result->maxUsedExposureTime);
        self::assertSame(0.5, $result->minUsedExposureTime);
        self::assertSame(2.0, $result->longestSourceExposureTime);
        self::assertEqualsWithDelta(0.3333333333, $result->shortestSourceExposureTime ?? 0.0, 0.0000000001);
        self::assertSame([[0.1, 0.2], [0.5]], $result->sequences);
    }

    #[Test]
    public function honoursBigEndianPayloads(): void
    {
        $byteOrder = Endian::Big;
        $summary   = [
            [10, 1],
            [8, 1],
            [10, 1],
            [4, 1],
            [5, 2],
            [1, 4],
            [5, 2],
            [1, 5],
        ];

        $payload = $this->buildPayload($summary, $byteOrder, [
            [0.25, 0.5],
        ]);

        $exifIfd = new Ifd([
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_UNDEFINED,
                strlen($payload),
                $payload,
            ),
        ]);

        $parsed = new ParsedExif(new Ifd([]), $exifIfd, null, null, null, byteOrder: $byteOrder);

        $result = $parsed->sourceExposureTimesOfCompositeImage();

        self::assertInstanceOf(SourceExposureTimes::class, $result);
        self::assertSame(10.0, $result->totalExposurePeriod);
        self::assertSame([[0.25, 0.5]], $result->sequences);
    }

    #[Test]
    public function returnsNullForTruncatedPayload(): void
    {
        $payload = "\x00\x01\x00\x00";
        $exifIfd = new Ifd([
            ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE => new IfdEntry(
                ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE,
                TiffConst::TYPE_UNDEFINED,
                strlen($payload),
                $payload,
            ),
        ]);

        $parsed = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsed->sourceExposureTimesOfCompositeImage());
    }

    /**
     * @param list<RationalPair> $summary
     * @param list<list<float>>  $sequences
     */
    private function buildPayload(array $summary, Endian $byteOrder, array $sequences): string
    {
        $payload = '';

        foreach ($summary as [$numerator, $denominator]) {
            $payload .= $this->packRational($numerator, $denominator, $byteOrder);
        }

        $payload .= $this->packShort(count($sequences), $byteOrder);

        foreach ($sequences as $sequence) {
            $payload .= $this->packShort(count($sequence), $byteOrder);

            foreach ($sequence as $value) {
                $payload .= $this->packRational((int) ($value * 1000), 1000, $byteOrder);
            }
        }

        return $payload;
    }

    private function packShort(int $value, Endian $byteOrder): string
    {
        return pack($byteOrder === Endian::Little ? 'v' : 'n', $value);
    }

    private function packRational(int $numerator, int $denominator, Endian $byteOrder): string
    {
        $format = $byteOrder === Endian::Little ? 'V' : 'N';

        return pack($format, $numerator) . pack($format, $denominator);
    }
}
