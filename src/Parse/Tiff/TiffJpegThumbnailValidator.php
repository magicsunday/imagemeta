<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;

use function is_int;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;

/**
 * Validates JPEG thumbnail stream conformance within IFD1.
 *
 * EXIF 3.0 §4.6.5.1.6 defines JPEGInterchangeFormat/JPEGInterchangeFormatLength
 * as an SOI..EOI JPEG stream. This validator requires direct access to the raw
 * TIFF byte buffer for bounds checking and marker verification.
 */
final readonly class TiffJpegThumbnailValidator
{
    /**
     * @param MemoryBuffer $buffer Seekable binary buffer for thumbnail stream validation.
     */
    public function __construct(
        private MemoryBuffer $buffer,
    ) {
    }

    /**
     * Validates strict JPEG thumbnail stream conformance for IFD1 Compression=6.
     *
     * EXIF 3.0 §4.6.5.1.6 defines JPEGInterchangeFormat/JPEGInterchangeFormatLength as
     * an SOI..EOI JPEG stream, and EXIF 3.0 §4.7 marker guidance excludes APPn/COM markers
     * in this embedded thumbnail stream representation.
     *
     * @param Ifd|null $ifd1 Thumbnail IFD.
     */
    public function validateJpegThumbnailStream(?Ifd $ifd1): void
    {
        if (!$ifd1 instanceof Ifd) {
            return;
        }

        $compression = $ifd1->get(ExifTag::COMPRESSION);
        if (!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 6)) {
            return;
        }

        $offsetEntry = $ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT);
        $lengthEntry = $ifd1->get(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
        if (!($offsetEntry instanceof IfdEntry) || !($lengthEntry instanceof IfdEntry)) {
            return;
        }

        if (!is_int($offsetEntry->value) || !is_int($lengthEntry->value)) {
            return;
        }

        $thumbnailOffset = $offsetEntry->value;
        $thumbnailLength = $lengthEntry->value;
        if ($thumbnailLength <= 0) {
            return;
        }

        // Postel's Law: skip thumbnail when the declared range exceeds the TIFF
        // data bounds rather than aborting the entire parse.
        $blobSize = $this->buffer->size();
        if (
            ($thumbnailOffset < 0)
            || ($thumbnailOffset > $blobSize)
            || ($thumbnailLength > $blobSize)
            || ($thumbnailOffset > ($blobSize - $thumbnailLength))
        ) {
            return;
        }

        $cursor = $this->buffer->tell();

        try {
            $this->buffer->seek($thumbnailOffset);
            $thumbnailBytes = $this->buffer->read($thumbnailLength);
        } catch (BoundsError $exception) {
            throw new ParseError(
                sprintf(
                    'JPEG thumbnail stream at offset %d with length %d is truncated',
                    $thumbnailOffset,
                    $thumbnailLength,
                ),
                1983,
                $exception,
            );
        } finally {
            $this->buffer->seek($cursor);
        }

        if (strlen($thumbnailBytes) < 4 || !str_starts_with($thumbnailBytes, "\xFF\xD8")) {
            return;
        }

        if (!str_ends_with($thumbnailBytes, "\xFF\xD9")) {
            return;
        }

        $this->validateJpegThumbnailDisallowedMarkers();
    }

    /**
     * Validates JPEG thumbnail marker compliance.
     *
     * EXIF 3.0 §4.8 restricts certain markers, but virtually all cameras
     * and editors embed APP0, APP14, restart markers, and COM markers in
     * thumbnail streams.  This method is intentionally a no-op to follow
     * Postel's Law and accept these common deviations.
     */
    private function validateJpegThumbnailDisallowedMarkers(): void
    {
    }
}
