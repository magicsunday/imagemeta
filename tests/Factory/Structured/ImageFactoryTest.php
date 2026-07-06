<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\FallbackIfdSet;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\FocalReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMapping;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMappingRegistry;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\ImageFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Riff\RiffAviHeader;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use MagicSunday\ImageMeta\Value\UserComment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
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
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ExifNumericList::class)]
#[UsesClass(FallbackIfdSet::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(FocalReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
#[UsesClass(UndefinedTextMarker::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(Image::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ExifXmpMapping::class)]
#[UsesClass(ExifXmpMappingRegistry::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(RiffAviHeader::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
#[UsesClass(UserComment::class)]
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

        $image = $this->createImageFromParsedExif($parsedExif);

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

        $image = $this->createImageFromParsedExif($parsedExif);

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

        $image = $this->createImageFromParsedExif($parsedExif);

        self::assertSame(ColorSpace::Uncalibrated, $image->colorSpace);
    }

    /**
     * Creates Metadata without EXIF or JPEG dimensions.
     * Confirms all image fields remain null when no sources are available.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $image = $this->createImageFromParsedExif(null);

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

        $parsedExif = $this->createParsedExifFromEntries($ifd0Entries, [], []);
        $image      = $this->createImageFromParsedExif($parsedExif);

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

        $parsedExif = $this->createParsedExifFromEntries([], $exifEntries, []);
        $image      = $this->createImageFromParsedExif($parsedExif);

        self::assertNull($image->colorSpace);
    }

    /**
     * Creates Metadata with no EXIF and no JPEG frame data but with QuickTime video
     * dimensions and bit depth.  Verifies the factory falls back to QuickTime values.
     */
    #[Test]
    public function fallsBackToQuickTimeDimensions(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::VIDEO_WIDTH_KEY     => 3840,
            QuickTimeMeta::VIDEO_HEIGHT_KEY    => 2160,
            QuickTimeMeta::VIDEO_BIT_DEPTH_KEY => 24,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(3840, $image->width);
        self::assertSame(2160, $image->height);
        self::assertSame(24, $image->bitsPerSample);
    }

    /**
     * Creates Metadata with only a QuickTime rotation of 90 degrees.
     * Verifies the factory maps the rotation to Orientation::RightTop.
     */
    #[Test]
    public function fallsBackToQuickTimeRotationAsOrientation(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::ROTATION_KEY => 90,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(Orientation::RightTop, $image->orientation);
    }

    /**
     * Creates Metadata with both EXIF dimensions and QuickTime dimensions.
     * Verifies that EXIF values take precedence over QuickTime values.
     */
    #[Test]
    public function exifDimensionsTakePrecedenceOverQuickTime(): void
    {
        $parsedExif = $this->parsedExif(
            width: 6000,
            height: 4000,
            orientation: Orientation::TopLeft,
            bitsPerSample: 8,
            colorSpace: ColorSpace::Srgb,
            interopIndex: null,
            imageUniqueId: null,
            documentName: null,
            imageDescription: null,
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::VIDEO_WIDTH_KEY     => 3840,
            QuickTimeMeta::VIDEO_HEIGHT_KEY    => 2160,
            QuickTimeMeta::VIDEO_BIT_DEPTH_KEY => 24,
            QuickTimeMeta::ROTATION_KEY        => 180,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(Orientation::TopLeft, $image->orientation);
        self::assertSame(8, $image->bitsPerSample);
    }

    /**
     * Passes an explicit XmpDocument parameter with dimensions and title.
     * Verifies the parameter-supplied XmpDocument takes precedence over Metadata::xmpDoc
     * for both the resolver (dimensions) and the direct XMP reads (title/description).
     */
    #[Test]
    public function xmpDocumentParameterTakesPrecedenceOverMetadataXmpDoc(): void
    {
        $metadataXmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '1000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '800',
            '{' . XmpNamespace::DC->value . '}title'             => 'Metadata Title',
            '{' . XmpNamespace::DC->value . '}description'       => 'Metadata Description',
        ]);

        $parameterXmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '2000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '1600',
            '{' . XmpNamespace::DC->value . '}title'             => 'Parameter Title',
            '{' . XmpNamespace::DC->value . '}description'       => 'Parameter Description',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $metadataXmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata, $parameterXmpDoc);

        self::assertSame(2000, $image->width);
        self::assertSame(1600, $image->height);
        self::assertSame('Parameter Title', $image->title);
        self::assertSame('Parameter Description', $image->description);
    }

    /**
     * Passes null for xmpDocument parameter while Metadata::xmpDoc provides XMP dimensions.
     * Verifies the factory falls back to Metadata::xmpDoc for the XMP fallback resolver.
     */
    #[Test]
    public function fallsBackToMetadataXmpDocResolverWhenParameterIsNull(): void
    {
        $metadataXmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '5000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '4000',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $metadataXmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(5000, $image->width);
        self::assertSame(4000, $image->height);
    }

    /**
     * Supplies XMP PixelXDimension and PixelYDimension without EXIF or JPEG dimensions.
     * Verifies the factory falls back to XMP resolver for width and height.
     */
    #[Test]
    public function fallsBackToXmpDimensionsWhenExifAndJpegAreAbsent(): void
    {
        $xmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '4000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '3000',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(4000, $image->width);
        self::assertSame(3000, $image->height);
    }

    /**
     * Supplies JPEG dimensions alongside XMP dimensions without an EXIF document.
     * Verifies JPEG dimensions take precedence over XMP fallback values.
     */
    #[Test]
    public function jpegDimensionsTakePrecedenceOverXmpDimensions(): void
    {
        $xmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '4000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '3000',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
            jpegFrameWidth: 1920,
            jpegFrameHeight: 1080,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(1920, $image->width);
        self::assertSame(1080, $image->height);
    }

    /**
     * Supplies XMP dimensions alongside QuickTime video dimensions without EXIF or JPEG.
     * Verifies XMP dimensions take precedence over QuickTime values.
     */
    #[Test]
    public function xmpDimensionsTakePrecedenceOverQuickTime(): void
    {
        $xmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}PixelXDimension' => '4000',
            '{' . XmpNamespace::EXIF->value . '}PixelYDimension' => '3000',
        ]);

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::VIDEO_WIDTH_KEY  => 3840,
            QuickTimeMeta::VIDEO_HEIGHT_KEY => 2160,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            xmpDoc: $xmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(4000, $image->width);
        self::assertSame(3000, $image->height);
    }

    /**
     * Supplies QuickTime dimensions alongside RIFF AVI header dimensions.
     * Verifies QuickTime dimensions take precedence over RIFF values.
     */
    #[Test]
    public function quickTimeDimensionsTakePrecedenceOverRiff(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::VIDEO_WIDTH_KEY  => 3840,
            QuickTimeMeta::VIDEO_HEIGHT_KEY => 2160,
        ]);

        $riffAviHeader = new RiffAviHeader(
            microSecPerFrame: 33333,
            width: 1280,
            height: 720,
            totalFrames: 100,
            streams: 2,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            riffAviHeader: $riffAviHeader,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(3840, $image->width);
        self::assertSame(2160, $image->height);
    }

    /**
     * Supplies only RIFF AVI header dimensions without any other dimension sources.
     * Verifies the factory falls back to RIFF dimensions as last resort.
     */
    #[Test]
    public function fallsBackToRiffAviHeaderDimensions(): void
    {
        $riffAviHeader = new RiffAviHeader(
            microSecPerFrame: 33333,
            width: 1280,
            height: 720,
            totalFrames: 100,
            streams: 2,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            riffAviHeader: $riffAviHeader,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(1280, $image->width);
        self::assertSame(720, $image->height);
    }

    /**
     * Supplies JPEG bitsPerSample alongside QuickTime video bit depth without EXIF.
     * Verifies JPEG bitsPerSample takes precedence over QuickTime bit depth.
     */
    #[Test]
    public function jpegBitsPerSampleTakesPrecedenceOverQuickTime(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::VIDEO_BIT_DEPTH_KEY => 24,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            jpegBitsPerSample: 8,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(8, $image->bitsPerSample);
    }

    /**
     * Supplies an EXIF imageUniqueId alongside an XMP ImageUniqueID resolver value.
     * Verifies the EXIF value takes precedence over the XMP fallback.
     */
    #[Test]
    public function exifImageUniqueIdTakesPrecedenceOverXmp(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: null,
            interopIndex: null,
            imageUniqueId: 'aabbccddeeff00112233445566778899',
            documentName: null,
            imageDescription: null,
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $xmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}ImageUniqueID' => 'xmp-unique-id-value',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
            xmpDoc: $xmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame('aabbccddeeff00112233445566778899', $image->imageUniqueId);
    }

    /**
     * Supplies only an XMP ImageUniqueID without an EXIF imageUniqueId.
     * Verifies the factory falls back to the XMP resolver value.
     */
    #[Test]
    public function fallsBackToXmpImageUniqueIdWhenExifIsAbsent(): void
    {
        $xmpDoc = new XmpDocument([
            '{' . XmpNamespace::EXIF->value . '}ImageUniqueID' => 'xmp-unique-id-value',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: $xmpDoc,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame('xmp-unique-id-value', $image->imageUniqueId);
    }

    /**
     * Supplies an XMP description alongside an EXIF imageDescription.
     * Verifies the XMP description takes precedence over the EXIF value.
     */
    #[Test]
    public function xmpDescriptionTakesPrecedenceOverExif(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: null,
            interopIndex: null,
            imageUniqueId: null,
            documentName: null,
            imageDescription: 'EXIF description',
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $xmpDocument = new XmpDocument([
            '{' . XmpNamespace::DC->value . '}description' => 'XMP description',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata, $xmpDocument);

        self::assertSame('XMP description', $image->description);
    }

    /**
     * Supplies both an XMP dc:title and a Photoshop Headline alongside EXIF imageTitle.
     * Verifies dc:title takes precedence over Headline.
     */
    #[Test]
    public function xmpTitleTakesPrecedenceOverHeadline(): void
    {
        $xmpDocument = new XmpDocument([
            '{' . XmpNamespace::DC->value . '}title'           => 'DC Title',
            '{' . XmpNamespace::PHOTOSHOP->value . '}Headline' => 'Photoshop Headline',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata, $xmpDocument);

        self::assertSame('DC Title', $image->title);
    }

    /**
     * Supplies only a Photoshop Headline without dc:title in the XMP document.
     * Verifies the Headline is used as fallback when dc:title is absent.
     */
    #[Test]
    public function headlineFallsBackWhenXmpTitleIsAbsent(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: null,
            interopIndex: null,
            imageUniqueId: null,
            documentName: null,
            imageDescription: null,
            imageTitle: 'EXIF Title',
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $xmpDocument = new XmpDocument([
            '{' . XmpNamespace::PHOTOSHOP->value . '}Headline' => 'Photoshop Headline',
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata, $xmpDocument);

        self::assertSame('Photoshop Headline', $image->title);
    }

    /**
     * Supplies both EXIF dimensions and JPEG frame dimensions with different values.
     * Verifies EXIF dimensions take precedence over JPEG frame dimensions.
     */
    #[Test]
    public function exifDimensionsTakePrecedenceOverJpegDimensions(): void
    {
        $parsedExif = $this->parsedExif(
            width: 6000,
            height: 4000,
            orientation: null,
            bitsPerSample: 14,
            colorSpace: null,
            interopIndex: null,
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
            jpegBitsPerSample: 8,
            jpegFrameWidth: 1920,
            jpegFrameHeight: 1080,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(6000, $image->width);
        self::assertSame(4000, $image->height);
        self::assertSame(14, $image->bitsPerSample);
    }

    /**
     * Supplies a QuickTime rotation of 0 degrees without EXIF orientation.
     * Verifies the factory maps rotation 0 to Orientation::TopLeft.
     */
    #[Test]
    public function mapsQuickTimeRotation0ToTopLeft(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::ROTATION_KEY => 0,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(Orientation::TopLeft, $image->orientation);
    }

    /**
     * Supplies a QuickTime rotation of 180 degrees without EXIF orientation.
     * Verifies the factory maps rotation 180 to Orientation::BottomRight.
     */
    #[Test]
    public function mapsQuickTimeRotation180ToBottomRight(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::ROTATION_KEY => 180,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(Orientation::BottomRight, $image->orientation);
    }

    /**
     * Supplies a QuickTime rotation of 270 degrees without EXIF orientation.
     * Verifies the factory maps rotation 270 to Orientation::LeftBottom.
     */
    #[Test]
    public function mapsQuickTimeRotation270ToLeftBottom(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::ROTATION_KEY => 270,
        ]);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
        );

        $factory = new ImageFactory();
        $image   = $factory->create($metadata);

        self::assertSame(Orientation::LeftBottom, $image->orientation);
    }

    /**
     * Supplies EXIF AdobeRGB ColorSpace (non-Uncalibrated, non-sRGB) with interop index R98.
     * Verifies the factory returns AdobeRGB without overriding it based on interop hints.
     */
    #[Test]
    public function returnsNonUncalibratedColorSpaceWithoutInteropOverride(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::AdobeRgb,
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

        $image = $this->createImageFromParsedExif($parsedExif);

        self::assertSame(ColorSpace::AdobeRgb, $image->colorSpace);
    }

    /**
     * Supplies EXIF Uncalibrated ColorSpace with a lowercase interop index 'r98'.
     * Verifies strtoupper normalizes the index and the factory returns sRGB.
     */
    #[Test]
    public function normalizesLowercaseInteropIndexR98ToSrgb(): void
    {
        $parsedExif = $this->parsedExif(
            width: null,
            height: null,
            orientation: null,
            bitsPerSample: null,
            colorSpace: ColorSpace::Uncalibrated,
            interopIndex: 'r98',
            imageUniqueId: null,
            documentName: null,
            imageDescription: null,
            imageTitle: null,
            componentsConfiguration: null,
            compressedBitsPerPixel: null,
            userComment: null,
            userCommentEncoding: null,
        );

        $image = $this->createImageFromParsedExif($parsedExif);

        self::assertSame(ColorSpace::Srgb, $image->colorSpace);
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

        return $this->createParsedExifFromEntries($ifd0Entries, $exifEntries, $interopEntries);
    }

    /**
     * @param array<int, IfdEntry> $ifd0Entries
     * @param array<int, IfdEntry> $exifEntries
     * @param array<int, IfdEntry> $interopEntries
     */
    private function createParsedExifFromEntries(array $ifd0Entries, array $exifEntries, array $interopEntries): ParsedExif
    {
        return new ParsedExif(
            ifd0: new Ifd($ifd0Entries),
            exifIfd: new Ifd($exifEntries),
            gpsIfd: null,
            interopIfd: new Ifd($interopEntries),
            ifd1: null,
        );
    }

    private function createImageFromParsedExif(?ParsedExif $parsedExif): Image
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        return (new ImageFactory())->create($metadata);
    }
}
