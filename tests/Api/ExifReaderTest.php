<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Exif\ExifReader;
use MagicSunday\ImageMeta\Exif\StructuredExif;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type StructuredExpectation array{
 *     standards: array{
 *         exifVersion: ?string,
 *         profile: ?string,
 *         flashpixVersion: ?string,
 *         tiffEpStandardId: array<int|string, mixed>|null,
 *         tiffEpStandardString: ?string,
 *     },
 *     exposure: array{iso: ?int},
 *     capture: array{
 *         dateTimeOriginal: ?string,
 *         offsetTimeOriginal: ?string,
 *         subSecTimeOriginal: ?string,
 *     },
 *     image: array{userComment: ?string, userCommentEncoding: ?string},
 *     interop: array{
 *         index: ?string,
 *         version: ?string,
 *         fileFormat: ?string,
 *         width: ?int,
 *         length: ?int,
 *     },
 *     preview: array{
 *         hasThumbnail: ?bool,
 *         hasPreview: ?bool,
 *         previewOffset: ?int,
 *         previewLength: ?int,
 *         previewWidth: ?int,
 *         previewHeight: ?int,
 *         previewBitDepth: ?int,
 *         previewCompression: ?int,
 *         previewCompressionName: ?string,
 *         previewColorSpace: ?int,
 *         previewColorSpaceName: ?string,
 *         previewEncoding: ?string,
 *         previewMimeType: ?string,
 *         previewScale: ?float,
 *         thumbnailOffset: ?int,
 *         thumbnailLength: ?int,
 *         thumbnailCompression: ?int,
 *         thumbnailCompressionName: ?string,
 *         thumbnailStripOffsets: array<int, int>|null,
 *         thumbnailStripByteCounts: array<int, int>|null,
 *         thumbnailTileOffsets: array<int, int>|null,
 *         thumbnailTileByteCounts: array<int, int>|null,
 *         previewStripOffsets: array<int, int>|null,
 *         previewStripByteCounts: array<int, int>|null,
 *         previewTileOffsets: array<int, int>|null,
 *         previewTileByteCounts: array<int, int>|null,
 *     },
 *     makerNotes: array{vendor: string, length: int, sha1: string, isSafe: ?bool}|null,
 *     environment: array{temperatureC: ?float, humidityPercent: ?float, pressureHpa: ?float},
 *     sensor: array{spatialFrequencyResponse: array<int|string, mixed>|null},
 * }
 * @phpstan-type ApiExpectation array{
 *     iso: ?int,
 *     dateTimeOriginal: ?string,
 *     userComment: ?string,
 *     userCommentEncoding: ?string,
 *     interop: array{
 *         index: ?string,
 *         version: ?string,
 *         fileFormat: ?string,
 *         width: ?int,
 *         length: ?int,
 *     },
 *     preview: array{
 *         hasThumbnail: ?bool,
 *         hasPreview: ?bool,
 *         previewOffset: ?int,
 *         previewLength: ?int,
 *         previewWidth: ?int,
 *         previewHeight: ?int,
 *         previewBitDepth: ?int,
 *         previewCompression: ?int,
 *         previewCompressionName: ?string,
 *         previewColorSpace: ?int,
 *         previewColorSpaceName: ?string,
 *         previewEncoding: ?string,
 *         previewMimeType: ?string,
 *         previewScale: ?float,
 *         thumbnailOffset: ?int,
 *         thumbnailLength: ?int,
 *         thumbnailCompression: ?int,
 *         thumbnailCompressionName: ?string,
 *         thumbnailStripOffsets: array<int, int>|null,
 *         thumbnailStripByteCounts: array<int, int>|null,
 *         thumbnailTileOffsets: array<int, int>|null,
 *         thumbnailTileByteCounts: array<int, int>|null,
 *         previewStripOffsets: array<int, int>|null,
 *         previewStripByteCounts: array<int, int>|null,
 *         previewTileOffsets: array<int, int>|null,
 *         previewTileByteCounts: array<int, int>|null,
 *     },
 * }
 * @phpstan-type ModelExpectation array{
 *     exifVersion: ?string,
 *     exifProfile: string,
 *     flashpixVersion: ?string,
 *     tiffEpStandardId: array<int|string, mixed>|null,
 *     tiffEpStandardString: ?string,
 * }
 *
 * @method static void assertStructuredMatches(string $fixture, Metadata $metadata, StructuredExpectation $expected)
 * @method static void assertApiMatches(string $fixture, StructuredExif $document, ApiExpectation $expected)
 * @method static void assertModelMatches(string $fixture, ?ParsedExif $document, ModelExpectation $expected)
 */
final class ExifReaderTest extends TestCase
{
    use ExifExpectationAssertions;

    #[Test]
    public function readsExifMetadataFromJpeg(): void
    {
        $reader = new ExifReader();
        $path   = dirname(__DIR__, 2) . '/test-images/Images/gps_exif_example.jpg';

        $document = $reader->read($path);

        self::assertTrue($document->hasData);
        self::assertSame('Canon', $document->camera->make);
        self::assertSame('Canon PowerShot SX130 IS', $document->camera->model);

        $exposure = $document->exposure;
        self::assertSame(80, $exposure->iso);
        self::assertEqualsWithDelta(0.01, $exposure->exposureTimeSec, 1e-5);
        self::assertEqualsWithDelta(3.4, $exposure->fNumber, 1e-2);

        $lens = $document->lens;
        self::assertEqualsWithDelta(5.0, $lens->focalLengthMm ?? 0.0, 1e-6);

        $image = $document->image;
        self::assertSame(4000, $image->width);
        self::assertSame('ASCII', $image->userCommentEncoding);
        self::assertSame(3000, $image->height);
        self::assertSame(Orientation::TOP_LEFT, $image->orientation);
        self::assertSame(ColorSpace::SRGB, $image->colorSpace);

        $gps = $document->gps;

        $interop = $document->interop;
        self::assertSame('R98', $interop->index);
        self::assertSame('0100', $interop->version);
        self::assertNull($interop->relatedImageFileFormat);
        self::assertSame(4000, $interop->relatedImageWidth);
        self::assertSame(3000, $interop->relatedImageLength);
        $latitudeCoordinate = $gps->latitudeCoordinate;
        self::assertNotNull($latitudeCoordinate);
        self::assertEqualsWithDelta(41.888948, $latitudeCoordinate->signed, 1e-6);

        $longitudeCoordinate = $gps->longitudeCoordinate;
        self::assertNotNull($longitudeCoordinate);
        self::assertEqualsWithDelta(-87.624494, $longitudeCoordinate->signed, 1e-6);

        $preview = $document->preview;
        self::assertTrue($preview->hasThumbnail);
        self::assertNull($preview->hasPreview);
        self::assertNull($preview->previewEncoding);
        self::assertNull($preview->previewMimeType);
        self::assertNull($preview->previewBitDepth);
        self::assertNull($preview->previewColorSpace);
        self::assertNull($preview->previewCompression);
        self::assertNull($preview->previewScale);
        self::assertNull($preview->previewOffset);
        self::assertNull($preview->previewLength);
    }

    #[Test]
    public function readsPreviewAndInteropMetadataFromExif30Image(): void
    {
        $reader  = new ExifReader();
        $fixture = 'exif-3-0.jpg';
        $path    = ExifVersionExpectations::path($fixture);

        $document = $reader->read($path);

        /**
         * @var array{
         *     structured: array<string, mixed>,
         *     api: ApiExpectation,
         *     model: ModelExpectation,
         * } $expectation
         */
        $expectation = ExifVersionExpectations::get($fixture);

        self::assertModelMatches($fixture, $document->raw, $expectation['model']);
        self::assertApiMatches($fixture, $document, $expectation['api']);
    }

    #[Test]
    public function returnsEmptyDocumentWhenExifIsMissing(): void
    {
        $reader = new ExifReader();

        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        self::assertNotFalse($white);
        imagefill($image, 0, 0, $white);

        $path = tempnam(sys_get_temp_dir(), 'exif');
        self::assertIsString($path);
        imagejpeg($image, $path);
        imagedestroy($image);

        try {
            $document = $reader->read($path);

            self::assertFalse($document->hasData);
            self::assertNull($document->camera->make);
            self::assertNull($document->lens->focalLengthMm);
            self::assertNull($document->gps->latitude);
        } finally {
            @unlink($path);
        }
    }
}
