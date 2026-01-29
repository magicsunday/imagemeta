<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Exif\SubFactory;

use MagicSunday\ImageMeta\Factory\Exif\SubFactory\ImageFactory;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function strlen;

#[CoversClass(ImageFactory::class)]
final class ImageFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            width: 6000,
            height: 4000,
            orientation: Orientation::TOP_LEFT,
            bitsPerSample: 8,
            colorSpace: ColorSpace::SRGB,
            interopIndex: null,
            imageUniqueId: 'ABC123',
            documentName: 'document.tif',
            imageDescription: 'Test image',
            imageTitle: 'Title',
            componentsConfiguration: [1, 2, 3, 0],
            compressedBitsPerPixel: 4.0,
            userComment: 'Test comment',
            userCommentEncoding: CharacterEncoding::ASCII->value,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(Orientation::TOP_LEFT, $image->orientation);
        self::assertSame(8, $image->bitsPerSample);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);
        self::assertSame('ABC123', $image->imageUniqueId);
        self::assertSame('document.tif', $image->documentName);
        self::assertSame('Test image', $image->description);
        self::assertSame('Title', $image->title);
        self::assertSame([1, 2, 3, 0], $image->componentsConfiguration);
        self::assertSame(4.0, $image->compressedBitsPerPixel);
        self::assertSame('Test comment', $image->userComment);
        self::assertSame(CharacterEncoding::ASCII->value, $image->userCommentEncoding);
    }

    #[Test]
    public function fallsBackToJpegDimensions(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            jpegBitsPerSample: 8,
            jpegFrameSamplingFactors: null,
            jpegYCbCrSubSampling: null,
            mimeType: null,
            fileSize: null,
            extension: null,
            digestSha1: null,
            digestMd5: null,
            jpegFrameWidth: 1920,
            jpegFrameHeight: 1080,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(1920, $image->width);
        self::assertSame(1080, $image->height);
        self::assertSame(8, $image->bitsPerSample);
        self::assertNull($image->orientation);
        self::assertNull($image->colorSpace);
    }

    #[Test]
    public function normalizesColorSpaceFromInterop(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::UNCALIBRATED,
            interopIndex: 'R98',
            imageUniqueId: null,
            documentName: null,
            imageDescription: null,
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(ColorSpace::SRGB, $image->colorSpace);
    }

    #[Test]
    public function keepsUncalibratedWhenInteropHintsNonSrgb(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::UNCALIBRATED,
            interopIndex: 'r03',
            imageUniqueId: null,
            documentName: null,
            imageDescription: null,
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(ColorSpace::UNCALIBRATED, $image->colorSpace);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertNull($image->width);
        self::assertNull($image->height);
        self::assertNull($image->orientation);
        self::assertNull($image->bitsPerSample);
        self::assertNull($image->colorSpace);
    }

    /**
     * @param list<int>|null $componentsConfiguration
     */
    private function parsedExif(
        ?int $width,
        ?int $height,
        ?Orientation $orientation,
        ?int $bitsPerSample,
        ?ColorSpace $colorSpace,
        ?string $interopIndex,
        ?string $imageUniqueId,
        ?string $documentName,
        ?string $imageDescription,
        ?string $imageTitle,
        ?array $componentsConfiguration,
        ?float $compressedBitsPerPixel,
        ?string $userComment,
        ?string $userCommentEncoding,
    ): ParsedExif {
        $ifd0Entries    = [];
        $exifEntries    = [];
        $interopEntries = [];

        if ($width !== null) {
            $ifd0Entries[ExifTag::IMAGE_WIDTH] = new IfdEntry(
                ExifTag::IMAGE_WIDTH,
                4,
                1,
                $width,
            );
        }

        if ($height !== null) {
            $ifd0Entries[ExifTag::IMAGE_LENGTH] = new IfdEntry(
                ExifTag::IMAGE_LENGTH,
                4,
                1,
                $height,
            );
        }

        if ($orientation instanceof Orientation) {
            $ifd0Entries[ExifTag::ORIENTATION] = new IfdEntry(
                ExifTag::ORIENTATION,
                3,
                1,
                $orientation->value,
            );
        }

        if ($bitsPerSample !== null) {
            $ifd0Entries[ExifTag::BITS_PER_SAMPLE] = new IfdEntry(
                ExifTag::BITS_PER_SAMPLE,
                3,
                1,
                $bitsPerSample,
            );
        }

        if ($colorSpace instanceof ColorSpace) {
            $exifEntries[ExifTag::COLOR_SPACE] = new IfdEntry(
                ExifTag::COLOR_SPACE,
                3,
                1,
                $colorSpace->value,
            );
        }

        if ($imageUniqueId !== null) {
            $exifEntries[ExifTag::IMAGE_UNIQUE_ID] = new IfdEntry(
                ExifTag::IMAGE_UNIQUE_ID,
                2,
                strlen($imageUniqueId),
                $imageUniqueId,
            );
        }

        if ($documentName !== null) {
            $ifd0Entries[TiffTag::DOCUMENT_NAME] = new IfdEntry(
                TiffTag::DOCUMENT_NAME,
                2,
                strlen($documentName),
                $documentName,
            );
        }

        if ($imageDescription !== null) {
            $ifd0Entries[ExifTag::IMAGE_DESCRIPTION] = new IfdEntry(
                ExifTag::IMAGE_DESCRIPTION,
                2,
                strlen($imageDescription),
                $imageDescription,
            );
        }

        if ($imageTitle !== null) {
            $exifEntries[ExifTag::IMAGE_TITLE] = new IfdEntry(
                ExifTag::IMAGE_TITLE,
                2,
                strlen($imageTitle),
                $imageTitle,
            );
        }

        if ($componentsConfiguration !== null) {
            $exifEntries[ExifTag::COMPONENTS_CONFIGURATION] = new IfdEntry(
                ExifTag::COMPONENTS_CONFIGURATION,
                7,
                count($componentsConfiguration),
                $componentsConfiguration,
            );
        }

        if ($compressedBitsPerPixel !== null) {
            $exifEntries[ExifTag::COMPRESSED_BITS_PER_PIXEL] = new IfdEntry(
                ExifTag::COMPRESSED_BITS_PER_PIXEL,
                5,
                1,
                $compressedBitsPerPixel,
            );
        }

        if ($userComment !== null) {
            $prefix = $userCommentEncoding !== null
                ? str_pad($userCommentEncoding, 8, '\0')
                : '        ';

            $rawComment = $prefix . $userComment;

            $exifEntries[ExifTag::USER_COMMENT] = new IfdEntry(
                ExifTag::USER_COMMENT,
                7,
                strlen($rawComment),
                $rawComment,
            );
        }

        if ($interopIndex !== null) {
            $interopEntries[ExifTag::INTEROPERABILITY_INDEX] = new IfdEntry(
                ExifTag::INTEROPERABILITY_INDEX,
                2,
                strlen($interopIndex),
                $interopIndex,
            );
        }

        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = new Ifd($exifEntries);
        $interop = new Ifd($interopEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: $interop,
            ifd1: null,
        );
    }
}
