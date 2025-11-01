<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Exif\StructuredExif as ApiStructuredExif;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif as ModelExifDocument;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifDocumentFallbackTest extends TestCase
{
    #[Test]
    public function exposesBestEffortIsoAndUserCommentEncoding(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::SENSITIVITY_TYPE           => new IfdEntry(ExifTag::SENSITIVITY_TYPE, 3, 1, 2),
            ExifTag::RECOMMENDED_EXPOSURE_INDEX => new IfdEntry(
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                5,
                1,
                new ExifRational(400, 1),
            ),
            ExifTag::USER_COMMENT       => new IfdEntry(ExifTag::USER_COMMENT, 7, 1, 'Shot with ND filter'),
            ExifTag::DATETIME_ORIGINAL  => new IfdEntry(ExifTag::DATETIME_ORIGINAL, 2, 1, 'invalid'),
            ExifTag::DATETIME_DIGITIZED => new IfdEntry(
                ExifTag::DATETIME_DIGITIZED,
                2,
                1,
                '2024:05:06 07:08:09',
            ),
            ExifTag::OFFSET_TIME_DIGITIZED => new IfdEntry(
                ExifTag::OFFSET_TIME_DIGITIZED,
                2,
                1,
                '+02:00',
            ),
        ]);

        $modelDocument = new ModelExifDocument($ifd0, $exifIfd, null, null, null);
        $apiDocument   = new ApiStructuredExif($modelDocument);

        $bestEffortOriginal = $modelDocument->dateTimeOriginalBestEffort();
        self::assertNotNull($bestEffortOriginal);
        self::assertSame('2024-05-06T07:08:09+02:00', $bestEffortOriginal->format(DATE_ATOM));

        $apiOriginal = $apiDocument->dateTimeOriginal;
        self::assertNotNull($apiOriginal);
        self::assertSame('2024-05-06T07:08:09+02:00', $apiOriginal->format(DATE_ATOM));

        self::assertSame(400, $apiDocument->iso);

        $exposure = $apiDocument->exposure;
        self::assertSame(400, $exposure->iso);

        $image = $apiDocument->image;
        self::assertSame('Shot with ND filter', $image->userComment);
        self::assertSame('ASCII', $image->userCommentEncoding);

        self::assertSame('Shot with ND filter', $apiDocument->userComment);
        self::assertSame('ASCII', $apiDocument->userCommentEncoding);
    }

    #[Test]
    public function previewMetadataRequiresValidDescriptor(): void
    {
        $ifd0 = new Ifd([]);

        $exifIfd = new Ifd([
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(ExifTag::PREVIEW_IMAGE_COMPRESSION, 3, 1, 6),
            ExifTag::PREVIEW_IMAGE_SCALE       => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(1, 2),
            ),
        ]);

        $apiDocument = new ApiStructuredExif(new ModelExifDocument($ifd0, $exifIfd, null, null, null));

        $preview = $apiDocument->preview;
        self::assertNull($preview->previewCompression);
        self::assertNull($preview->previewScale);
        self::assertFalse($preview->hasPreview);
        self::assertNull($preview->previewWidth);
        self::assertNull($preview->previewHeight);
        self::assertNull($preview->previewBitDepth);
        self::assertNull($preview->previewEncoding);
        self::assertNull($preview->previewMimeType);
        self::assertNull($preview->previewOffset);
        self::assertNull($preview->previewLength);
    }

    #[Test]
    public function previewMetadataExposedWhenDescriptorIsComplete(): void
    {
        $ifd0 = new Ifd([
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 8_192),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 2_048),
        ]);

        $thumbnailIfd = new Ifd([
            ExifTag::COMPRESSION                    => new IfdEntry(ExifTag::COMPRESSION, 3, 1, Compression::JPEG->value),
            ExifTag::JPEG_INTERCHANGE_FORMAT        => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT, 4, 1, 8_192),
            ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH => new IfdEntry(ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH, 4, 1, 2_048),
        ]);

        $exifIfd = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 65_536),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 32_768),
            ExifTag::PREVIEW_IMAGE_WIDTH       => new IfdEntry(ExifTag::PREVIEW_IMAGE_WIDTH, 4, 1, 1_600),
            ExifTag::PREVIEW_IMAGE_HEIGHT      => new IfdEntry(ExifTag::PREVIEW_IMAGE_HEIGHT, 4, 1, 900),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COMPRESSION,
                3,
                1,
                Compression::JPEG->value,
            ),
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(1, 2),
            ),
            ExifTag::PREVIEW_IMAGE_ENCODING  => new IfdEntry(ExifTag::PREVIEW_IMAGE_ENCODING, 2, 4, 'JPEG'),
            ExifTag::PREVIEW_IMAGE_MIME_TYPE => new IfdEntry(ExifTag::PREVIEW_IMAGE_MIME_TYPE, 2, 10, 'image/jpeg'),
        ]);

        $apiDocument = new ApiStructuredExif(new ModelExifDocument($ifd0, $exifIfd, null, null, $thumbnailIfd));

        $preview = $apiDocument->preview;

        self::assertTrue($preview->hasThumbnail);
        self::assertTrue($preview->hasPreview);
        self::assertSame(1_600, $preview->previewWidth);
        self::assertSame(900, $preview->previewHeight);
        self::assertSame(65_536, $preview->previewOffset);
        self::assertSame(32_768, $preview->previewLength);
        self::assertSame('JPEG', $preview->previewEncoding);
        self::assertSame('image/jpeg', $preview->previewMimeType);
        self::assertSame(Compression::JPEG, $preview->previewCompression);
        self::assertEqualsWithDelta(0.5, $preview->previewScale ?? 0.0, 1e-6);
        self::assertSame(8_192, $preview->thumbnailOffset);
        self::assertSame(2_048, $preview->thumbnailLength);
        self::assertSame(Compression::JPEG, $preview->thumbnailCompression);
        self::assertNull($preview->thumbnailStripOffsets);
        self::assertNull($preview->thumbnailStripByteCounts);
        self::assertNull($preview->thumbnailTileOffsets);
        self::assertNull($preview->thumbnailTileByteCounts);
        self::assertNull($preview->previewStripOffsets);
        self::assertNull($preview->previewStripByteCounts);
        self::assertNull($preview->previewTileOffsets);
        self::assertNull($preview->previewTileByteCounts);
    }

    #[Test]
    public function previewMetadataResolvesFromFallbackIfd(): void
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([]);

        $fallbackPreview = new Ifd([
            ExifTag::PREVIEW_IMAGE_START       => new IfdEntry(ExifTag::PREVIEW_IMAGE_START, 4, 1, 131_072),
            ExifTag::PREVIEW_IMAGE_LENGTH      => new IfdEntry(ExifTag::PREVIEW_IMAGE_LENGTH, 4, 1, 65_536),
            ExifTag::PREVIEW_IMAGE_WIDTH       => new IfdEntry(ExifTag::PREVIEW_IMAGE_WIDTH, 4, 1, 1_024),
            ExifTag::PREVIEW_IMAGE_HEIGHT      => new IfdEntry(ExifTag::PREVIEW_IMAGE_HEIGHT, 4, 1, 768),
            ExifTag::PREVIEW_IMAGE_COMPRESSION => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_COMPRESSION,
                3,
                1,
                Compression::JPEG_OLD_STYLE->value,
            ),
            ExifTag::PREVIEW_IMAGE_SCALE => new IfdEntry(
                ExifTag::PREVIEW_IMAGE_SCALE,
                5,
                1,
                new ExifRational(3, 4),
            ),
            ExifTag::PREVIEW_IMAGE_ENCODING  => new IfdEntry(ExifTag::PREVIEW_IMAGE_ENCODING, 2, 4, 'JPEG'),
            ExifTag::PREVIEW_IMAGE_MIME_TYPE => new IfdEntry(ExifTag::PREVIEW_IMAGE_MIME_TYPE, 2, 10, 'image/jpeg'),
            ExifTag::PREVIEW_IMAGE_BIT_DEPTH => new IfdEntry(ExifTag::PREVIEW_IMAGE_BIT_DEPTH, 3, 1, 8),
        ]);

        $document = new ModelExifDocument(
            $ifd0,
            $exifIfd,
            null,
            null,
            null,
            null,
            [],
            [
                0x2000 => $fallbackPreview,
            ],
        );

        $preview = (new ApiStructuredExif($document))->preview;

        self::assertTrue($preview->hasPreview);
        self::assertSame(1_024, $preview->previewWidth);
        self::assertSame(768, $preview->previewHeight);
        self::assertSame(131_072, $preview->previewOffset);
        self::assertSame(65_536, $preview->previewLength);
        self::assertSame('JPEG', $preview->previewEncoding);
        self::assertSame('image/jpeg', $preview->previewMimeType);
        self::assertSame(8, $preview->previewBitDepth);
        self::assertSame(Compression::JPEG_OLD_STYLE, $preview->previewCompression);
        self::assertEqualsWithDelta(0.75, $preview->previewScale ?? 0.0, 1e-6);
        self::assertNull($preview->thumbnailOffset);
        self::assertNull($preview->thumbnailLength);
        self::assertNull($preview->thumbnailCompression);
        self::assertNull($preview->thumbnailStripOffsets);
        self::assertNull($preview->thumbnailStripByteCounts);
        self::assertNull($preview->thumbnailTileOffsets);
        self::assertNull($preview->thumbnailTileByteCounts);
        self::assertNull($preview->previewStripOffsets);
        self::assertNull($preview->previewStripByteCounts);
        self::assertNull($preview->previewTileOffsets);
        self::assertNull($preview->previewTileByteCounts);
    }
}
