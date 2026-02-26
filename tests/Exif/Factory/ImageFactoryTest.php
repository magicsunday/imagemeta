<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\ImageFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function strlen;

/**
 * Exercises ImageFactory for mapping EXIF image tags to the Image value object.
 * It verifies dimensions, orientation, and color space conversions.
 * The suite covers character encoding for comments and metadata strings.
 * This ensures image metadata is normalized consistently from EXIF inputs.
 *
 * @internal
 */
#[CoversClass(ImageFactory::class)]
final class ImageFactoryTest extends TestCase
{
    /**
     * Supplies EXIF image tags for dimensions, orientation, color space, and comments.
     * Verifies ImageFactory maps these values into the Image value object.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $parsedExif = $this->parsedExif(
            width: 6000,
            height: 4000,
            orientation: Orientation::TopLeft,
            bitsPerSample: 8,
            colorSpace: ColorSpace::Srgb,
            interopIndex: null,
            imageUniqueId: '00112233445566778899aabbccddeeff',
            documentName: 'document.tif',
            imageDescription: 'Test image',
            imageTitle: 'Title',
            componentsConfiguration: [1, 2, 3, 0],
            compressedBitsPerPixel: 4.0,
            userComment: 'Test comment',
            userCommentEncoding: CharacterEncoding::Ascii->value,
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
        self::assertSame(Orientation::TopLeft, $image->orientation);
        self::assertSame(8, $image->bitsPerSample);
        self::assertSame(ColorSpace::Srgb, $image->colorSpace);
        self::assertSame('00112233445566778899aabbccddeeff', $image->imageUniqueId);
        self::assertSame('document.tif', $image->documentName);
        self::assertSame('Test image', $image->description);
        self::assertSame('Title', $image->title);
        self::assertSame([1, 2, 3, 0], $image->componentsConfiguration);
        self::assertSame(4.0, $image->compressedBitsPerPixel);
        self::assertNotNull($image->comment);
        self::assertSame('Test comment', $image->comment->value);
        self::assertSame(CharacterEncoding::Ascii->value, $image->comment->encoding);
    }

    /**
     * Provides JPEG frame dimensions and precision without an EXIF document.
     * Ensures the factory falls back to JPEG dimensions and bits per sample.
     */
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

    /**
     * Uses an uncalibrated ColorSpace with interop index R98.
     * Confirms the factory normalizes the color space to sRGB for R98.
     */
    #[Test]
    public function normalizesColorSpaceFromInterop(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::Uncalibrated,
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

        self::assertSame(ColorSpace::Srgb, $image->colorSpace);
    }

    /**
     * Uses an uncalibrated ColorSpace with an interop index that does not signal sRGB.
     * Ensures the factory keeps the uncalibrated color space.
     */
    #[Test]
    public function keepsUncalibratedWhenInteropHintsNonSrgb(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::Uncalibrated,
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

        self::assertSame(ColorSpace::Uncalibrated, $image->colorSpace);
    }

    /**
     * Creates Metadata without EXIF or JPEG dimensions.
     * Confirms all image fields remain null when no sources are available.
     */
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
     * Supplies IFD entries with wrong TIFF types for width, height, and orientation.
     * Verifies the factory degrades gracefully to null for mistyped dimension fields.
     */
    #[Test]
    public function returnsNullDimensionsWhenIfdEntriesHaveWrongTypes(): void
    {
        // Put ASCII strings where LONG integers are expected for width/height
        $ifd0Entries = [
            ExifTag::IMAGE_WIDTH => new IfdEntry(
                ExifTag::IMAGE_WIDTH,
                TiffConst::TYPE_ASCII,
                4,
                'wide',
            ),
            ExifTag::IMAGE_LENGTH => new IfdEntry(
                ExifTag::IMAGE_LENGTH,
                TiffConst::TYPE_ASCII,
                4,
                'tall',
            ),
        ];

        // Put an invalid orientation code
        $ifd0Entries[ExifTag::ORIENTATION] = new IfdEntry(
            ExifTag::ORIENTATION,
            3,
            1,
            255,
        );

        $ifd0    = new Ifd($ifd0Entries);
        $exifIfd = new Ifd([]);

        $parsedExif = new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertNull($image->width);
        self::assertNull($image->height);
        // orientation() is non-nullable, defaults to TopLeft for invalid values
        self::assertSame(Orientation::TopLeft, $image->orientation);
    }

    /**
     * Supplies an invalid ColorSpace enum backing value.
     * Verifies the factory returns null rather than crashing.
     */
    #[Test]
    public function returnsNullColorSpaceForInvalidEnumValue(): void
    {
        $exifEntries = [
            ExifTag::COLOR_SPACE => new IfdEntry(
                ExifTag::COLOR_SPACE,
                3,
                1,
                9999,
            ),
        ];

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($exifEntries);

        $parsedExif = new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

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
                TiffConst::TYPE_ASCII,
                4,
                $interopIndex . "\0",
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
