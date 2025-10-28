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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifBackfillMatrixTest extends TestCase
{
    private const string IMAGE_DIR = __DIR__ . '/../../test-images/Images';

    /**
     * Ensures the metadata reader exposes fallback EXIF fields across representative reference images.
     *
     * @param string                 $file                        Fixture filename relative to the reference image directory.
     * @param int|null               $expectedIso                 Expected ISO value or null when not recorded.
     * @param string|null            $expectedDateTimeOriginal    Expected capture timestamp in ISO-8601 format or null.
     * @param string|null            $expectedUserComment         Expected decoded user comment value or null.
     * @param string|null            $expectedUserCommentEncoding Expected best-effort user comment encoding or null.
     * @param array<string, int|string|null> $expectedInterop     Expected interoperability metadata components.
     */
    #[Test]
    #[DataProvider('provideReferenceImages')]
    public function extractsFallbackMetadataFromReferenceImages(
        string $file,
        ?int $expectedIso,
        ?string $expectedDateTimeOriginal,
        ?string $expectedUserComment,
        ?string $expectedUserCommentEncoding,
        array $expectedInterop,
    ): void {
        $structured = (new MetadataReader())
            ->read(self::IMAGE_DIR . '/' . $file)
            ->structured();

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
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     int|null,
     *     string|null,
     *     string|null,
     *     string|null,
     *     array{index:?string, version:?string, fileFormat:?string, width:?int, length:?int}
     * }>
     */
    public static function provideReferenceImages(): iterable
    {
        yield 'gps_exif_example' => [
            'gps_exif_example.jpg',
            80,
            '2011-12-06T11:08:37+00:00',
            '400 N Michigan Ave, Chicago, IL 60611, USA',
            'ASCII',
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => null,
                'width' => 4000,
                'length' => 3000,
            ],
        ];

        yield 'gps_exif_ambiguous' => [
            'gps_exif_ambiguous.jpg',
            100,
            '2010-08-04T20:30:54+00:00',
            null,
            null,
            [
                'index' => 'R98',
                'version' => '0100',
                'fileFormat' => null,
                'width' => null,
                'length' => null,
            ],
        ];

        yield 'metadata_test_iim_xmp_exif' => [
            'metadata_test_iim_xmp_exif.jpg',
            40,
            '2017-05-29T11:11:16+00:00',
            "\n",
            'ASCII',
            [
                'index' => null,
                'version' => null,
                'fileFormat' => null,
                'width' => null,
                'length' => null,
            ],
        ];
    }
}
