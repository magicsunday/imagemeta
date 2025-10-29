<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Support;

use MagicSunday\ImageMeta\Api\ExifDocument as ApiExifDocument;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument as ModelExifDocument;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Curate\Exif\Structured\Preview as StructuredPreview;
use MagicSunday\ImageMeta\Value\Preview as PreviewValue;
use PHPUnit\Framework\Assert;

/**
 * Shared assertions for verifying EXIF expectation matrices.
 */
trait ExifExpectationAssertions
{
    /**
     * @param Metadata $metadata
     * @param array{
     *     standards: array{exifVersion:?string, profile:?string, flashpixVersion:?string, tiffEpStandardId:?array, tiffEpStandardString:?string},
     *     exposure: array{iso:?int},
     *     capture: array{dateTimeOriginal:?string, offsetTimeOriginal:?string, subSecTimeOriginal:?string},
     *     image: array{userComment:?string, userCommentEncoding:?string},
     *     interop: array{index:?string, version:?string, fileFormat:?string, width:?int, length:?int},
     *     preview: array{
     *         hasThumbnail:?bool,
     *         hasPreview:?bool,
     *         previewOffset:?int,
     *         previewLength:?int,
     *         previewWidth:?int,
     *         previewHeight:?int,
     *         previewBitDepth:?int,
     *         previewCompression:?int,
     *         previewCompressionName:?string,
     *         previewColorSpace:?int,
     *         previewColorSpaceName:?string,
     *         previewEncoding:?string,
     *         previewMimeType:?string,
     *         previewScale:?float,
     *     },
     *     makerNotes:?array{vendor:string,length:int,sha1:string,isSafe:?bool},
     *     environment: array{temperatureC:?float, humidityPercent:?float, pressureHpa:?float},
     *     sensor: array{spatialFrequencyResponse:?array},
     * } $expected
     */
    private static function assertStructuredMatches(string $fixture, Metadata $metadata, array $expected): void
    {
        $structured = $metadata->structured();
        $standards = $structured->technical->standards;
        $expectedStandards = $expected['standards'];

        Assert::assertSame($expectedStandards['exifVersion'], $standards->exifVersion, sprintf('%s: EXIF version', $fixture));
        Assert::assertSame($expectedStandards['profile'], $standards->profile, sprintf('%s: EXIF profile', $fixture));
        Assert::assertSame($expectedStandards['flashpixVersion'], $standards->flashpixVersion, sprintf('%s: FlashPix version', $fixture));
        Assert::assertSame($expectedStandards['tiffEpStandardId'], $standards->tiffEpStandardId, sprintf('%s: TIFF/EP standard ID', $fixture));
        Assert::assertSame($expectedStandards['tiffEpStandardString'], $standards->tiffEpStandardString, sprintf('%s: TIFF/EP standard string', $fixture));

        Assert::assertSame($expected['exposure']['iso'], $structured->exposure->iso, sprintf('%s: ISO fallback', $fixture));

        $temporal = $structured->capture->temporal;
        $expectedCapture = $expected['capture']['dateTimeOriginal'];
        $actualOriginal = $temporal->original;
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

        $image = $structured->media->image;
        Assert::assertSame($expected['image']['userComment'], $image->userComment, sprintf('%s: UserComment fallback', $fixture));
        Assert::assertSame($expected['image']['userCommentEncoding'], $image->userCommentEncoding, sprintf('%s: UserComment encoding', $fixture));

        $interop = $structured->technical->interop;
        $expectedInterop = $expected['interop'];
        Assert::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: Interop index', $fixture));
        Assert::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: Interop version', $fixture));
        Assert::assertSame($expectedInterop['fileFormat'], $interop->relatedImageFileFormat, sprintf('%s: Interop file format', $fixture));
        Assert::assertSame($expectedInterop['width'], $interop->relatedImageWidth, sprintf('%s: Interop width', $fixture));
        Assert::assertSame($expectedInterop['length'], $interop->relatedImageLength, sprintf('%s: Interop length', $fixture));

        self::assertPreviewMatches($fixture, $expected['preview'], $structured->media->preview);

        $expectedMaker = $expected['makerNotes'];
        $actualMaker = $metadata->makerNotes;
        if ($expectedMaker === null) {
            Assert::assertNull($actualMaker, sprintf('%s: Maker notes digest', $fixture));
        } else {
            Assert::assertNotNull($actualMaker, sprintf('%s: Maker notes digest', $fixture));
            Assert::assertSame($expectedMaker['vendor'], $actualMaker->vendor(), sprintf('%s: Maker note vendor', $fixture));
            Assert::assertSame($expectedMaker['length'], $actualMaker->length(), sprintf('%s: Maker note length', $fixture));
            Assert::assertSame($expectedMaker['sha1'], $actualMaker->sha1(), sprintf('%s: Maker note SHA-1', $fixture));
            Assert::assertSame($expectedMaker['isSafe'], $actualMaker->isSafe(), sprintf('%s: Maker note safety', $fixture));
        }

        $expectedEnv = $expected['environment'];
        $raw = $metadata->exifDoc;
        $environmentMatchers = [
            'temperatureC'    => 'temperatureCelsius',
            'humidityPercent' => 'humidityPercent',
            'pressureHpa'     => 'pressureHPa',
        ];

        foreach ($environmentMatchers as $key => $method) {
            $expectedValue = $expectedEnv[$key];
            $actualValue   = $raw?->$method();

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
            $structured->sensor->hardware->spatialFrequencyResponse,
            sprintf('%s: Spatial frequency response', $fixture),
        );
    }

    /**
     * @param array{
     *     iso:?int,
     *     dateTimeOriginal:?string,
     *     userComment:?string,
     *     userCommentEncoding:?string,
     *     interop: array{index:?string, version:?string, fileFormat:?string, width:?int, length:?int},
     *     preview: array{
     *         hasThumbnail:?bool,
     *         hasPreview:?bool,
     *         previewOffset:?int,
     *         previewLength:?int,
     *         previewWidth:?int,
     *         previewHeight:?int,
     *         previewBitDepth:?int,
     *         previewCompression:?int,
     *         previewCompressionName:?string,
     *         previewColorSpace:?int,
     *         previewColorSpaceName:?string,
     *         previewEncoding:?string,
     *         previewMimeType:?string,
     *         previewScale:?float,
     *     },
     * } $expected
     */
    private static function assertApiMatches(string $fixture, ApiExifDocument $document, array $expected): void
    {
        Assert::assertTrue($document->hasData(), sprintf('%s: Document contains EXIF data', $fixture));
        Assert::assertSame($expected['iso'], $document->iso(), sprintf('%s: ISO value', $fixture));

        $expectedOriginal = $expected['dateTimeOriginal'];
        $actualOriginal = $document->dateTimeOriginal();
        if ($expectedOriginal === null) {
            Assert::assertNull($actualOriginal, sprintf('%s: API DateTimeOriginal', $fixture));
        } else {
            Assert::assertNotNull($actualOriginal, sprintf('%s: API DateTimeOriginal', $fixture));
            Assert::assertSame($expectedOriginal, $actualOriginal->format(DATE_ATOM), sprintf('%s: API DateTimeOriginal value', $fixture));
        }

        Assert::assertSame($expected['userComment'], $document->userComment(), sprintf('%s: API UserComment', $fixture));
        Assert::assertSame($expected['userCommentEncoding'], $document->userCommentEncoding(), sprintf('%s: API UserComment encoding', $fixture));

        $interop = $document->interop();
        $expectedInterop = $expected['interop'];
        Assert::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: API Interop index', $fixture));
        Assert::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: API Interop version', $fixture));
        Assert::assertSame($expectedInterop['fileFormat'], $interop->relatedImageFileFormat, sprintf('%s: API Interop file format', $fixture));
        Assert::assertSame($expectedInterop['width'], $interop->relatedImageWidth, sprintf('%s: API Interop width', $fixture));
        Assert::assertSame($expectedInterop['length'], $interop->relatedImageLength, sprintf('%s: API Interop length', $fixture));

        self::assertPreviewMatches($fixture, $expected['preview'], $document->preview());
    }

    /**
     * @param array{
     *     exifVersion:?string,
     *     exifProfile:string,
     *     flashpixVersion:?string,
     *     tiffEpStandardId:?array,
     *     tiffEpStandardString:?string,
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
     *     hasThumbnail:?bool,
     *     hasPreview:?bool,
     *     previewOffset:?int,
     *     previewLength:?int,
     *     previewWidth:?int,
     *     previewHeight:?int,
     *     previewBitDepth:?int,
     *     previewCompression:?int,
     *     previewCompressionName:?string,
     *     previewColorSpace:?int,
     *     previewColorSpaceName:?string,
     *     previewEncoding:?string,
     *     previewMimeType:?string,
     *     previewScale:?float,
     * } $expected
     */
    private static function assertPreviewMatches(string $fixture, array $expected, PreviewValue|StructuredPreview $preview): void
    {
        Assert::assertSame($expected['hasThumbnail'], $preview->hasThumbnail, sprintf('%s: Preview thumbnail availability', $fixture));
        Assert::assertSame($expected['hasPreview'], $preview->hasPreview, sprintf('%s: Preview availability', $fixture));
        Assert::assertSame($expected['previewOffset'], $preview->previewOffset, sprintf('%s: Preview offset', $fixture));
        Assert::assertSame($expected['previewLength'], $preview->previewLength, sprintf('%s: Preview length', $fixture));
        Assert::assertSame($expected['previewWidth'], $preview->previewWidth, sprintf('%s: Preview width', $fixture));
        Assert::assertSame($expected['previewHeight'], $preview->previewHeight, sprintf('%s: Preview height', $fixture));
        Assert::assertSame($expected['previewBitDepth'], $preview->previewBitDepth, sprintf('%s: Preview bit depth', $fixture));

        if ($expected['previewCompression'] === null) {
            Assert::assertNull($preview->previewCompression, sprintf('%s: Preview compression enum', $fixture));
        } else {
            Assert::assertNotNull($preview->previewCompression, sprintf('%s: Preview compression enum', $fixture));
            Assert::assertSame($expected['previewCompression'], $preview->previewCompression->value, sprintf('%s: Preview compression value', $fixture));
            Assert::assertSame($expected['previewCompressionName'], $preview->previewCompression->name, sprintf('%s: Preview compression name', $fixture));
        }

        if ($expected['previewColorSpace'] === null) {
            Assert::assertNull($preview->previewColorSpace, sprintf('%s: Preview colour space enum', $fixture));
        } else {
            Assert::assertNotNull($preview->previewColorSpace, sprintf('%s: Preview colour space enum', $fixture));
            Assert::assertSame($expected['previewColorSpace'], $preview->previewColorSpace->value, sprintf('%s: Preview colour space value', $fixture));
            Assert::assertSame($expected['previewColorSpaceName'], $preview->previewColorSpace->name, sprintf('%s: Preview colour space name', $fixture));
        }

        Assert::assertSame($expected['previewEncoding'], $preview->previewEncoding, sprintf('%s: Preview encoding', $fixture));
        Assert::assertSame($expected['previewMimeType'], $preview->previewMimeType, sprintf('%s: Preview MIME type', $fixture));

        if ($expected['previewScale'] === null) {
            Assert::assertNull($preview->previewScale, sprintf('%s: Preview scale', $fixture));
        } else {
            Assert::assertNotNull($preview->previewScale, sprintf('%s: Preview scale', $fixture));
            Assert::assertEqualsWithDelta($expected['previewScale'], $preview->previewScale, 1e-6, sprintf('%s: Preview scale value', $fixture));
        }
    }
}
