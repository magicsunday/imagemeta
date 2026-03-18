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
use function max;
use function str_repeat;
use function strlen;

/**
 * Verifies TIFF FreeOffsets/FreeByteCounts paired semantics.
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
final class TiffExifParserFreeSpaceTagsTest extends TestCase
{
    /**
     * Valid paired free-space arrays parse.
     */
    #[Test]
    public function acceptsValidPairedFreeSpaceTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildFreeSpaceTiff(
                freeOffsets: [256, 512],
                freeByteCounts: [16, 32],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(TiffTag::FREE_OFFSETS));
        self::assertNotNull($parsed->ifd0->get(TiffTag::FREE_BYTE_COUNTS));
    }

    /**
     * Presence of one free-space tag requires the counterpart.
     */
    #[Test]
    public function rejectsMissingFreeSpaceCounterpartTag(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FreeOffsets and FreeByteCounts must both be present');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFreeSpaceTiff(
                freeOffsets: [256],
                freeByteCounts: null,
            ),
        );
    }

    /**
     * Free-space arrays must have equal item counts.
     */
    #[Test]
    public function rejectsFreeSpaceCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FreeOffsets count 2 must match FreeByteCounts count 1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFreeSpaceTiff(
                freeOffsets: [256, 512],
                freeByteCounts: [16],
            ),
        );
    }

    /**
     * Offset+byteCount must remain within file size.
     */
    #[Test]
    public function rejectsOutOfBoundsFreeSpaceRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Free-space range index 0 exceeds TIFF data length');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFreeSpaceTiff(
                freeOffsets: [4096],
                freeByteCounts: [1024],
                padToRequiredSize: false,
            ),
        );
    }

    /**
     * Free byte counts must be strictly positive.
     */
    #[Test]
    public function rejectsNonPositiveFreeByteCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FreeByteCounts index 0 must be > 0');

        (new TiffExifParser())->parseFromBlob(
            $this->buildFreeSpaceTiff(
                freeOffsets: [256],
                freeByteCounts: [0],
            ),
        );
    }

    /**
     * Builds a minimal TIFF with optional free-space tags.
     *
     * @param list<int>|null $freeOffsets
     * @param list<int>|null $freeByteCounts
     */
    private function buildFreeSpaceTiff(?array $freeOffsets, ?array $freeByteCounts, bool $padToRequiredSize = true): string
    {
        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 64) . pack('v', 0),
        ];

        $payloadByTag = [];

        if (is_array($freeOffsets)) {
            $entries[TiffTag::FREE_OFFSETS] = pack('v', TiffTag::FREE_OFFSETS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', count($freeOffsets));
            $payloadByTag[TiffTag::FREE_OFFSETS] = $this->packLongArray($freeOffsets);
        }

        if (is_array($freeByteCounts)) {
            $entries[TiffTag::FREE_BYTE_COUNTS] = pack('v', TiffTag::FREE_BYTE_COUNTS)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', count($freeByteCounts));
            $payloadByTag[TiffTag::FREE_BYTE_COUNTS] = $this->packLongArray($freeByteCounts);
        }

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

            if (strlen($payload) <= 4) {
                $ifdEntries .= $prefix . $payload . str_repeat("\0", 4 - strlen($payload));

                continue;
            }

            $ifdEntries  .= $prefix . pack('V', $nextOffset);
            $payloadTail .= $payload;
            $nextOffset += strlen($payload);
        }

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $payloadTail;

        if ($padToRequiredSize && is_array($freeOffsets) && is_array($freeByteCounts) && ($freeOffsets !== []) && ($freeByteCounts !== [])) {
            $requiredSize = 0;

            $limit = min(count($freeOffsets), count($freeByteCounts));

            for ($index = 0; $index < $limit; ++$index) {
                $requiredSize = max($requiredSize, $freeOffsets[$index] + $freeByteCounts[$index]);
            }

            if ($requiredSize > strlen($blob)) {
                $blob .= str_repeat("\0", $requiredSize - strlen($blob));
            }
        }

        return $blob;
    }

    /**
     * @param list<int> $values
     */
    private function packLongArray(array $values): string
    {
        $payload = '';

        foreach ($values as $value) {
            $payload .= pack('V', $value);
        }

        return $payload;
    }
}
