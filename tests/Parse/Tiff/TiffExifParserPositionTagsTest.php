<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function ksort;
use function strlen;

/**
 * Verifies TIFF XPosition/YPosition conformance rules.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserPositionTagsTest extends TestCase
{
    /**
     * Valid positive XPosition/YPosition parse successfully.
     */
    #[Test]
    public function acceptsValidPositionTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::X_POSITION));
        self::assertNotNull($parsed->ifd0->get(TiffTag::Y_POSITION));
    }

    /**
     * Wrong type/count for position tags is rejected.
     */
    #[Test]
    public function rejectsWrongPositionTagLayout(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('XPosition must be RATIONAL[1].');

        (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_SHORT,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 1,
            ),
        );
    }

    /**
     * Zero YPosition is tolerated.
     */
    #[Test]
    public function toleratesZeroYPosition(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 0,
                yPositionDenominator: 1,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::Y_POSITION));
    }

    /**
     * Denominator zero is malformed and must be rejected.
     */
    #[Test]
    public function rejectsMalformedPositionRational(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('YPosition denominator must be non-zero');

        (new TiffExifParser())->parseFromBlob(
            $this->buildPositionTiff(
                xPositionType: TiffConst::TYPE_RATIONAL,
                xPositionCount: 1,
                xPositionNumerator: 5,
                xPositionDenominator: 1,
                yPositionType: TiffConst::TYPE_RATIONAL,
                yPositionCount: 1,
                yPositionNumerator: 10,
                yPositionDenominator: 0,
            ),
        );
    }

    /**
     * Builds a minimal TIFF with XPosition/YPosition entries.
     */
    private function buildPositionTiff(
        int $xPositionType,
        int $xPositionCount,
        int $xPositionNumerator,
        int $xPositionDenominator,
        int $yPositionType,
        int $yPositionCount,
        int $yPositionNumerator,
        int $yPositionDenominator,
    ): string {
        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            TiffTag::X_POSITION => pack('v', TiffTag::X_POSITION)
                . pack('v', $xPositionType)
                . pack('V', $xPositionCount),
            TiffTag::Y_POSITION => pack('v', TiffTag::Y_POSITION)
                . pack('v', $yPositionType)
                . pack('V', $yPositionCount),
        ];

        $payloadByTag = [
            TiffTag::X_POSITION => pack('V', $xPositionNumerator) . pack('V', $xPositionDenominator),
            TiffTag::Y_POSITION => pack('V', $yPositionNumerator) . pack('V', $yPositionDenominator),
        ];

        ksort($entries);

        $ifdOffset   = 8;
        $entryCount  = count($entries);
        $ifdSize     = 2 + (12 * $entryCount) + 4;
        $nextOffset  = $ifdOffset + $ifdSize;
        $ifdEntries  = '';
        $payloadTail = '';

        foreach ($entries as $tag => $prefix) {
            if (!isset($payloadByTag[$tag])) {
                $ifdEntries .= $prefix;

                continue;
            }

            $payload = $payloadByTag[$tag];

            $ifdEntries .= $prefix . pack('V', $nextOffset);
            $payloadTail .= $payload;
            $nextOffset += strlen($payload);
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $payloadTail;
    }
}
