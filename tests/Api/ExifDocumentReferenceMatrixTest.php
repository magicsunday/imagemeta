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
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use PHPUnit\Framework\Attributes\DataProviderExternal;
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
 * @method static void assertApiMatches(string $fixture, ApiStructuredExif $document, ApiExpectation $expected)
 * @method static void assertModelMatches(string $fixture, ?ParsedExif $document, ModelExpectation $expected)
 */
final class ExifDocumentReferenceMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @param ApiExpectation $expectedApi
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideApi')]
    public function exposesFallbackMetadataFromReferenceImages(
        string $fixture,
        array $expectedApi,
    ): void {
        $metadata = (new MetadataReader())
            ->read(ExifVersionExpectations::path($fixture));

        $modelDocument = $metadata->exifDoc;
        self::assertNotNull($modelDocument, sprintf('Reference EXIF document missing for %s', $fixture));

        /**
         * @var array{
         *     structured: array<string, mixed>,
         *     api: ApiExpectation,
         *     model: ModelExpectation,
         * } $expectation
         */
        $expectation = ExifVersionExpectations::get($fixture);
        self::assertModelMatches($fixture, $modelDocument, $expectation['model']);

        $document = new ApiStructuredExif($modelDocument);

        self::assertSame($modelDocument, $document->raw(), sprintf('%s: Raw document reference', $fixture));

        self::assertApiMatches($fixture, $document, $expectedApi);
    }
}
