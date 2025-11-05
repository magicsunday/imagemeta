<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type StructuredExpectation array{
 *     standards: array{
 *         exifVersion: string|null,
 *         profile: string|null,
 *         flashpixVersion: string|null,
 *         tiffEpStandardId: array<int, int>|null,
 *         tiffEpStandardString: string|null,
 *     },
 *     exposure: array{iso: int|null},
 *     capture: array{
 *         dateTimeOriginal: string|null,
 *         offsetTimeOriginal: string|null,
 *         subSecTimeOriginal: string|null,
 *     },
 *     image: array{
 *         userComment: string|null,
 *         userCommentEncoding: string|null,
 *     },
 *     interop: array{
 *         index: string|null,
 *         version: string|null,
 *         fileFormat: string|null,
 *         width: int|null,
 *         length: int|null,
 *     },
 *     preview: array{
 *         hasThumbnail: bool|null,
 *         hasPreview: bool|null,
 *         previewOffset: int|null,
 *         previewLength: int|null,
 *         previewWidth: int|null,
 *         previewHeight: int|null,
 *         previewBitDepth: int|null,
 *         previewCompression: int|null,
 *         previewCompressionName: string|null,
 *         previewColorSpace: int|null,
 *         previewColorSpaceName: string|null,
 *         previewEncoding: string|null,
 *         previewMimeType: string|null,
 *         previewScale: float|null,
 *         thumbnailOffset: int|null,
 *         thumbnailLength: int|null,
 *         thumbnailCompression: int|null,
 *         thumbnailCompressionName: string|null,
 *         thumbnailStripOffsets: array<int, int>|null,
 *         thumbnailStripByteCounts: array<int, int>|null,
 *         thumbnailTileOffsets: array<int, int>|null,
 *         thumbnailTileByteCounts: array<int, int>|null,
 *         previewStripOffsets: array<int, int>|null,
 *         previewStripByteCounts: array<int, int>|null,
 *         previewTileOffsets: array<int, int>|null,
 *         previewTileByteCounts: array<int, int>|null,
 *     },
 *     makerNotes: array{
 *         vendor: string,
 *         length: int,
 *         sha1: string,
 *         isSafe: bool|null,
 *     }|null,
 *     environment: array{
 *         temperatureC: float|null,
 *         humidityPercent: float|null,
 *         pressureHpa: float|null,
 *     },
 *     sensor: array{
 *         spatialFrequencyResponse: array<array-key, mixed>|null,
 *     },
 * }
 * @phpstan-type ApiExpectation array{
 *     iso: int|null,
 *     dateTimeOriginal: string|null,
 *     userComment: string|null,
 *     userCommentEncoding: string|null,
 *     interop: array{
 *         index: string|null,
 *         version: string|null,
 *         fileFormat: string|null,
 *         width: int|null,
 *         length: int|null,
 *     },
 *     preview: array{
 *         hasThumbnail: bool|null,
 *         hasPreview: bool|null,
 *         previewOffset: int|null,
 *         previewLength: int|null,
 *         previewWidth: int|null,
 *         previewHeight: int|null,
 *         previewBitDepth: int|null,
 *         previewCompression: int|null,
 *         previewCompressionName: string|null,
 *         previewColorSpace: int|null,
 *         previewColorSpaceName: string|null,
 *         previewEncoding: string|null,
 *         previewMimeType: string|null,
 *         previewScale: float|null,
 *         thumbnailOffset: int|null,
 *         thumbnailLength: int|null,
 *         thumbnailCompression: int|null,
 *         thumbnailCompressionName: string|null,
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
 *     exifVersion: string|null,
 *     exifProfile: string,
 *     flashpixVersion: string|null,
 *     tiffEpStandardId: array<int, int>|null,
 *     tiffEpStandardString: string|null,
 * }
 */
#[CoversNothing]
final class ExifBackfillMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @param StructuredExpectation $expectedStructured
     * @param ApiExpectation        $expectedApi
     * @param ModelExpectation      $expectedModel
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideAll')]
    public function extractsFallbackMetadataFromReferenceImages(
        string $fixture,
        array $expectedStructured,
        array $expectedApi,
        array $expectedModel,
    ): void {
        $metadata = (new MetadataReader())->read(ExifVersionExpectations::path($fixture));

        self::assertStructuredMatches($fixture, $metadata, $expectedStructured);

        $document = $metadata->structured();
        self::assertApiMatches($fixture, $document, $expectedApi);

        self::assertModelMatches($fixture, $metadata->exifDoc, $expectedModel);
    }
}
