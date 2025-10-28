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
use MagicSunday\ImageMeta\Value\Enum\Compression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifBackfillMatrixTest extends TestCase
{
    private const string IMAGE_DIR = __DIR__ . '/../Fixtures/Images/ExifVersions';

    /**
     * Ensures the metadata reader exposes fallback EXIF fields across representative reference images.
     *
     * @param string                 $file                        Fixture filename relative to the reference image directory.
     * @param int|null               $expectedIso                 Expected ISO value or null when not recorded.
     * @param string|null            $expectedDateTimeOriginal    Expected capture timestamp in ISO-8601 format or null.
     * @param string|null            $expectedUserComment         Expected decoded user comment value or null.
     * @param string|null            $expectedUserCommentEncoding Expected best-effort user comment encoding or null.
     * @param array{flashpixVersion:string,tiffEpStandardId:?list<int>,tiffEpStandardString:?string} $expectedStandards Expected standards metadata components.
     * @param array<string, int|string|null> $expectedInterop         Expected interoperability metadata components.
     * @param array<string, int|float|bool|null> $expectedPreview     Expected preview descriptor components.
    */
    #[Test]
    #[DataProvider('provideReferenceImages')]
    public function extractsFallbackMetadataFromReferenceImages(
        string $file,
        ?int $expectedIso,
        ?string $expectedDateTimeOriginal,
        ?string $expectedUserComment,
        ?string $expectedUserCommentEncoding,
        array $expectedStandards,
        array $expectedInterop,
        array $expectedPreview,
    ): void {
        $structured = (new MetadataReader())
            ->read(self::IMAGE_DIR . '/' . $file)
            ->structured();

        $standards = $structured->technical->standards;
        self::assertSame($expectedStandards['flashpixVersion'], $standards->flashpixVersion, sprintf('%s: FlashPix version', $file));
        self::assertSame($expectedStandards['tiffEpStandardId'], $standards->tiffEpStandardId, sprintf('%s: TIFF/EP standard id', $file));
        self::assertSame($expectedStandards['tiffEpStandardString'], $standards->tiffEpStandardString, sprintf('%s: TIFF/EP standard string', $file));

        self::assertSame($expectedIso, $structured->exposure->iso, sprintf('%s: ISO fallback', $file));

        $original = $structured->capture->temporal->original;
        if ($expectedDateTimeOriginal === null) {
            self::assertNull($original, sprintf('%s: DateTimeOriginal fallback', $file));
        } else {
            self::assertNotNull($original, sprintf('%s: DateTimeOriginal fallback', $file));
            self::assertSame(
                $expectedDateTimeOriginal,
                $original->format(DATE_ATOM),
                sprintf('%s: DateTimeOriginal fallback', $file),
            );
        }

        self::assertSame(
            $expectedUserComment,
            $structured->media->image->userComment,
            sprintf('%s: UserComment fallback', $file),
        );
        self::assertSame(
            $expectedUserCommentEncoding,
            $structured->media->image->userCommentEncoding,
            sprintf('%s: UserComment encoding fallback', $file),
        );

        $interop = $structured->technical->interop;
        self::assertSame($expectedInterop['index'], $interop->index, sprintf('%s: Interop index', $file));
        self::assertSame($expectedInterop['version'], $interop->version, sprintf('%s: Interop version', $file));
        self::assertSame(
            $expectedInterop['fileFormat'],
            $interop->relatedImageFileFormat,
            sprintf('%s: Interop file format', $file),
        );
        self::assertSame(
            $expectedInterop['width'],
            $interop->relatedImageWidth,
            sprintf('%s: Interop width', $file),
        );
        self::assertSame(
            $expectedInterop['length'],
            $interop->relatedImageLength,
            sprintf('%s: Interop length', $file),
        );

        $preview = $structured->media->preview;
        self::assertSame($expectedPreview['hasPreview'], $preview->hasPreview, sprintf('%s: Preview availability', $file));
        self::assertSame($expectedPreview['offset'], $preview->previewOffset, sprintf('%s: Preview offset', $file));
        self::assertSame($expectedPreview['length'], $preview->previewLength, sprintf('%s: Preview length', $file));
        self::assertSame($expectedPreview['width'], $preview->previewWidth, sprintf('%s: Preview width', $file));
        self::assertSame($expectedPreview['height'], $preview->previewHeight, sprintf('%s: Preview height', $file));
        self::assertSame($expectedPreview['bitDepth'], $preview->previewBitDepth, sprintf('%s: Preview bit depth', $file));
        self::assertSame($expectedPreview['compression'], $preview->previewCompression?->value, sprintf('%s: Preview compression', $file));
        if ($expectedPreview['scale'] === null) {
            self::assertNull($preview->previewScale, sprintf('%s: Preview scale', $file));
        } else {
            self::assertNotNull($preview->previewScale, sprintf('%s: Preview scale', $file));
            self::assertEqualsWithDelta(
                $expectedPreview['scale'],
                $preview->previewScale,
                1e-6,
                sprintf('%s: Preview scale', $file),
            );
        }
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     int|null,
     *     string|null,
     *     string|null,
     *     string|null,
     *     array{flashpixVersion:string,tiffEpStandardId:?list<int>,tiffEpStandardString:?string},
     *     array{index:?string, version:?string, fileFormat:?string, width:?int, length:?int},
     *     array{hasPreview:?bool, offset:?int, length:?int, width:?int, height:?int, bitDepth:?int, compression:?int, scale:?float|null}
     * }>
     */
    public static function provideReferenceImages(): iterable
    {
        yield '1.0' => [
            'exif-1-0.jpg',
            100,
            '2020-01-02T03:04:05+00:00',
            'Legacy 1.0 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => null,
                'tiffEpStandardString' => null,
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '1.1' => [
            'exif-1-1.jpg',
            110,
            '2021-02-03T04:05:06+01:00',
            'Legacy 1.1 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => null,
                'tiffEpStandardString' => null,
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.1' => [
            'exif-2-1.jpg',
            210,
            '2022-03-04T05:06:07-05:00',
            'Legacy 2.1 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => null,
                'tiffEpStandardString' => null,
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.2' => [
            'exif-2-2.jpg',
            220,
            '2023-04-05T06:07:08+02:30',
            'Legacy 2.2 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => null,
                'tiffEpStandardString' => null,
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.21' => [
            'exif-2-21.jpg',
            221,
            '2024-05-06T07:08:09+09:00',
            'Résumé 2.21',
            'UNICODE',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => null,
                'tiffEpStandardString' => null,
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.3' => [
            'exif-2-3.jpg',
            230,
            '2025-06-07T08:09:10+00:00',
            'Legacy 2.3 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => [2, 0, 0, 0],
                'tiffEpStandardString' => '2.0.0.0',
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.31' => [
            'exif-2-31.jpg',
            231,
            '2026-07-08T09:10:11-03:30',
            'Café 2.31',
            'UNICODE',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => [2, 0, 0, 1],
                'tiffEpStandardString' => '2.0.0.1',
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '2.32' => [
            'exif-2-32.jpg',
            232,
            '2027-08-09T10:11:12+05:45',
            'ユニコード 2.32',
            'UNICODE',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => [2, 0, 0, 2],
                'tiffEpStandardString' => '2.0.0.2',
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => null,
                'offset' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'bitDepth' => null,
                'compression' => null,
                'scale' => null,
            ],
        ];

        yield '3.0' => [
            'exif-3-0.jpg',
            300,
            '2028-09-10T11:12:13+00:00',
            'Preview 3.0 comment',
            'ASCII',
            [
                'flashpixVersion' => '1.00',
                'tiffEpStandardId' => [48, 49, 48, 48, 0],
                'tiffEpStandardString' => '0100',
            ],
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => 'JPEG',
                'width' => 4000,
                'length' => 3000,
            ],
            [
                'hasPreview' => true,
                'offset' => 0x0000_4000,
                'length' => 0x0000_2000,
                'width' => 1_600,
                'height' => 900,
                'bitDepth' => 8,
                'compression' => Compression::JPEG_OLD_STYLE->value,
                'scale' => 0.5,
            ],
        ];
    }
}
