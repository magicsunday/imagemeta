<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use MagicSunday\ImageMeta\Curate\StructuredMetadata as ApiStructuredMetadata;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif as ModelExifDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Thumbnail;
use PHPUnit\Framework\Assert;

/**
 * Shared assertions for verifying EXIF expectation matrices.
 */
trait ExifExpectationAssertions
{
    /**
     * @param array{
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
     *         hasThumbnail: bool,
     *         thumbnailOffset: int|null,
     *         thumbnailLength: int|null,
     *         thumbnailCompression: int|null,
     *         thumbnailCompressionName: string|null,
     *         thumbnailStripOffsets: array<int, int>|null,
     *         thumbnailStripByteCounts: array<int, int>|null,
     *         thumbnailTileOffsets: array<int, int>|null,
     *         thumbnailTileByteCounts: array<int, int>|null,
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
     * } $expected
     */
    private static function assertStructuredMatches(string $fixture, Metadata $metadata, array $expected): void
    {
        $structured        = $metadata->structured();
        $standards         = $structured->standards;
        $expectedStandards = $expected['standards'];

        Assert::assertSame($expectedStandards['exifVersion'], $standards->exifVersion, sprintf('%s: EXIF version', $fixture));
        Assert::assertSame($expectedStandards['profile'], $standards->profile, sprintf('%s: EXIF profile', $fixture));
        Assert::assertSame($expectedStandards['flashpixVersion'], $standards->flashpixVersion, sprintf('%s: FlashPix version', $fixture));
        Assert::assertSame($expectedStandards['tiffEpStandardId'], $standards->tiffEpStandardId, sprintf('%s: TIFF/EP standard ID', $fixture));
        Assert::assertSame($expectedStandards['tiffEpStandardString'], $standards->tiffEpStandardString, sprintf('%s: TIFF/EP standard string', $fixture));

        Assert::assertSame($expected['exposure']['iso'], $structured->exposure->iso, sprintf('%s: ISO fallback', $fixture));

        $temporal        = $structured->temporal;
        $expectedCapture = $expected['capture']['dateTimeOriginal'];
        $actualOriginal  = $temporal->original;
        if ($expectedCapture === null) {
            Assert::assertNull($actualOriginal, sprintf('%s: DateTimeOriginal fallback', $fixture));
        } else {
            Assert::assertNotNull($actualOriginal, sprintf('%s: DateTimeOriginal fallback', $fixture));
            Assert::assertSame($expectedCapture, $actualOriginal->format(DATE_ATOM), sprintf('%s: DateTimeOriginal value', $fixture));
        }

        Assert::assertSame(
            $expected['capture']['offsetTimeOriginal'],
            $temporal->offsetTimeOriginal,
            sprintf('%s: OffsetTimeOriginal', $fixture),
        );

        Assert::assertSame(
            $expected['capture']['subSecTimeOriginal'],
            $temporal->subSecTimeOriginal,
            sprintf('%s: SubSecTimeOriginal', $fixture),
        );

        $image = $structured->image;
        Assert::assertSame($expected['image']['userComment'], $image->userComment, sprintf('%s: UserComment fallback', $fixture));
        Assert::assertSame($expected['image']['userCommentEncoding'], $image->userCommentEncoding, sprintf('%s: UserComment encoding', $fixture));

        $interop         = $structured->interop;
        $expectedInterop = $expected['interop'];
        Assert::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: Interop index', $fixture));
        Assert::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: Interop version', $fixture));
        Assert::assertSame($expectedInterop['fileFormat'], $interop->relatedImageFileFormat, sprintf('%s: Interop file format', $fixture));
        Assert::assertSame($expectedInterop['width'], $interop->relatedImageWidth, sprintf('%s: Interop width', $fixture));
        Assert::assertSame($expectedInterop['length'], $interop->relatedImageLength, sprintf('%s: Interop length', $fixture));

        self::assertPreviewMatches($fixture, $expected['preview'], $structured->thumbnail);

        $expectedMaker = $expected['makerNotes'];
        $actualMaker   = $metadata->makerNotes;
        if ($expectedMaker === null) {
            Assert::assertNull($actualMaker, sprintf('%s: Maker notes digest', $fixture));
        } else {
            Assert::assertNotNull($actualMaker, sprintf('%s: Maker notes digest', $fixture));
            Assert::assertSame($expectedMaker['vendor'], $actualMaker->vendor, sprintf('%s: Maker note vendor', $fixture));
            Assert::assertSame($expectedMaker['length'], $actualMaker->length, sprintf('%s: Maker note length', $fixture));
            Assert::assertSame($expectedMaker['sha1'], $actualMaker->sha1, sprintf('%s: Maker note SHA-1', $fixture));
            Assert::assertSame($expectedMaker['isSafe'], $actualMaker->isSafe, sprintf('%s: Maker note safety', $fixture));
        }

        $expectedEnv = $expected['environment'];
        $raw         = $metadata->exifDoc;
        /** @var array<string, callable(ModelExifDocument): ?float> $environmentMatchers */
        $environmentMatchers = [
            'temperatureC'    => static fn (ModelExifDocument $document): ?float => $document->temperatureCelsius(),
            'humidityPercent' => static fn (ModelExifDocument $document): ?float => $document->humidityPercent(),
            'pressureHpa'     => static fn (ModelExifDocument $document): ?float => $document->pressureHPa(),
        ];

        foreach ($environmentMatchers as $key => $resolver) {
            $expectedValue = $expectedEnv[$key];
            $actualValue   = $raw instanceof ModelExifDocument ? $resolver($raw) : null;

            if ($expectedValue === null) {
                Assert::assertNull($actualValue, sprintf('%s: %s', $fixture, ucfirst($key)));
                continue;
            }

            Assert::assertNotNull($actualValue, sprintf('%s: %s presence', $fixture, ucfirst($key)));
            Assert::assertEqualsWithDelta(
                $expectedValue,
                $actualValue,
                1e-6,
                sprintf('%s: %s value', $fixture, ucfirst($key)),
            );
        }

        Assert::assertSame(
            $expected['sensor']['spatialFrequencyResponse'],
            $structured->sensor->spatialFrequencyResponse,
            sprintf('%s: Spatial frequency response', $fixture),
        );
    }

    /**
     * @param array{
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
     *         hasThumbnail: bool,
     *         thumbnailOffset: int|null,
     *         thumbnailLength: int|null,
     *         thumbnailCompression: int|null,
     *         thumbnailCompressionName: string|null,
     *         thumbnailStripOffsets: array<int, int>|null,
     *         thumbnailStripByteCounts: array<int, int>|null,
     *         thumbnailTileOffsets: array<int, int>|null,
     *         thumbnailTileByteCounts: array<int, int>|null,
     *     },
     * } $expected
     */
    private static function assertApiMatches(string $fixture, ApiStructuredMetadata $document, array $expected): void
    {
        $exposure = $document->exposure;
        Assert::assertSame($expected['iso'], $exposure->iso, sprintf('%s: ISO value', $fixture));

        $expectedOriginal = $expected['dateTimeOriginal'];
        $actualOriginal   = $document->temporal->original;
        if ($expectedOriginal === null) {
            Assert::assertNull($actualOriginal, sprintf('%s: Structured DateTimeOriginal', $fixture));
        } else {
            Assert::assertNotNull($actualOriginal, sprintf('%s: Structured DateTimeOriginal', $fixture));
            Assert::assertSame($expectedOriginal, $actualOriginal->format(DATE_ATOM), sprintf('%s: Structured DateTimeOriginal value', $fixture));
        }

        $image = $document->image;
        Assert::assertSame($expected['userComment'], $image->userComment, sprintf('%s: Structured UserComment', $fixture));
        Assert::assertSame($expected['userCommentEncoding'], $image->userCommentEncoding, sprintf('%s: Structured UserComment encoding', $fixture));

        $interop         = $document->interop;
        $expectedInterop = $expected['interop'];
        Assert::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: Structured Interop index', $fixture));
        Assert::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: Structured Interop version', $fixture));
        Assert::assertSame($expectedInterop['fileFormat'], $interop->relatedImageFileFormat, sprintf('%s: Structured Interop file format', $fixture));
        Assert::assertSame($expectedInterop['width'], $interop->relatedImageWidth, sprintf('%s: Structured Interop width', $fixture));
        Assert::assertSame($expectedInterop['length'], $interop->relatedImageLength, sprintf('%s: Structured Interop length', $fixture));

        self::assertPreviewMatches($fixture, $expected['preview'], $document->thumbnail);
    }

    /**
     * @param array{
     *     exifVersion: string|null,
     *     exifProfile: string,
     *     flashpixVersion: string|null,
     *     tiffEpStandardId: array<int, int>|null,
     *     tiffEpStandardString: string|null,
     * } $expected
     */
    private static function assertModelMatches(string $fixture, ?ModelExifDocument $document, array $expected): void
    {
        Assert::assertNotNull($document, sprintf('%s: Raw EXIF document', $fixture));

        Assert::assertSame($expected['exifVersion'], $document->exifVersion(), sprintf('%s: Raw EXIF version', $fixture));
        Assert::assertSame($expected['exifProfile'], $document->exifProfile(), sprintf('%s: Raw EXIF profile', $fixture));
        Assert::assertSame($expected['flashpixVersion'], $document->flashpixVersion(), sprintf('%s: Raw FlashPix version', $fixture));
        Assert::assertSame($expected['tiffEpStandardId'], $document->tiffEpStandardId(), sprintf('%s: Raw TIFF/EP standard id', $fixture));
        Assert::assertSame($expected['tiffEpStandardString'], $document->tiffEpStandardIdString(), sprintf('%s: Raw TIFF/EP standard string', $fixture));
    }

    /**
     * @param array{
     *     hasThumbnail: bool,
     *     thumbnailOffset: int|null,
     *     thumbnailLength: int|null,
     *     thumbnailCompression: int|null,
     *     thumbnailCompressionName: string|null,
     *     thumbnailStripOffsets: array<int, int>|null,
     *     thumbnailStripByteCounts: array<int, int>|null,
     *     thumbnailTileOffsets: array<int, int>|null,
     *     thumbnailTileByteCounts: array<int, int>|null,
     * } $expected
     */
    private static function assertPreviewMatches(string $fixture, array $expected, Thumbnail $thumbnail): void
    {
        Assert::assertSame($expected['hasThumbnail'], $thumbnail->hasThumbnail, sprintf('%s: Thumbnail availability', $fixture));
        Assert::assertSame($expected['thumbnailOffset'], $thumbnail->thumbnailOffset, sprintf('%s: Thumbnail offset', $fixture));
        Assert::assertSame($expected['thumbnailLength'], $thumbnail->thumbnailLength, sprintf('%s: Thumbnail length', $fixture));

        if ($expected['thumbnailCompression'] === null) {
            Assert::assertNull($thumbnail->thumbnailCompression, sprintf('%s: Thumbnail compression enum', $fixture));
        } else {
            Assert::assertNotNull($thumbnail->thumbnailCompression, sprintf('%s: Thumbnail compression enum', $fixture));
            Assert::assertSame($expected['thumbnailCompression'], $thumbnail->thumbnailCompression->value, sprintf('%s: Thumbnail compression value', $fixture));
            Assert::assertSame($expected['thumbnailCompressionName'], $thumbnail->thumbnailCompression->name, sprintf('%s: Thumbnail compression name', $fixture));
        }

        Assert::assertSame($expected['thumbnailStripOffsets'], $thumbnail->thumbnailStripOffsets, sprintf('%s: Thumbnail strip offsets', $fixture));
        Assert::assertSame($expected['thumbnailStripByteCounts'], $thumbnail->thumbnailStripByteCounts, sprintf('%s: Thumbnail strip byte counts', $fixture));
        Assert::assertSame($expected['thumbnailTileOffsets'], $thumbnail->thumbnailTileOffsets, sprintf('%s: Thumbnail tile offsets', $fixture));
        Assert::assertSame($expected['thumbnailTileByteCounts'], $thumbnail->thumbnailTileByteCounts, sprintf('%s: Thumbnail tile byte counts', $fixture));
    }
}
