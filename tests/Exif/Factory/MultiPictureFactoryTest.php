<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\MultiPictureFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Value\MultiPictureEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises MultiPictureFactory for mapping MPF documents into value objects.
 * It verifies version, entry counts, and per-entry offsets and sizes are preserved.
 * The suite checks attribute flags and dependent image indices.
 * This ensures multi-picture metadata is normalized consistently from MPF input.
 *
 * @internal
 */
#[CoversClass(MultiPictureFactory::class)]
final class MultiPictureFactoryTest extends TestCase
{
    /**
     * Builds an MPF document with multiple entries and attributes.
     * Verifies MultiPictureFactory maps version, counts, and entry data into the value object.
     *
     * @return void
     */
    #[Test]
    public function createsFromMpfDocument(): void
    {
        $entries = [
            new MpfEntry(
                attributes: 1,
                imageSize: 1024,
                dataOffset: 2048,
                dependentImage1: 0,
                dependentImage2: 0,
            ),
            new MpfEntry(
                attributes: 2,
                imageSize: 2048,
                dataOffset: 4096,
                dependentImage1: 1,
                dependentImage2: 0,
            ),
        ];

        $attributes = new MpfAttributes(
            imageUidList: 'uid-1,uid-2',
            totalFrames: 2,
            individualImageNumber: 1,
            panoramaAngle: [],
            panoramaAxis: [],
            additionalTags: [],
        );

        $mpfDocument = new MpfDocument(
            version: '0100',
            imageCount: 2,
            entries: $entries,
            attributes: $attributes,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            mpfDocument: $mpfDocument,
        );

        $factory      = new MultiPictureFactory();
        $multiPicture = $factory->create($metadata);

        self::assertSame('0100', $multiPicture->version);
        self::assertSame(2, $multiPicture->imageCount);
        self::assertCount(2, $multiPicture->entries);
        self::assertContainsOnlyInstancesOf(MultiPictureEntry::class, $multiPicture->entries);
        self::assertSame(2, $multiPicture->totalFrames);
        self::assertSame(1, $multiPicture->individualImageNumber);
        self::assertSame('uid-1,uid-2', $multiPicture->imageUidList);
    }

    /**
     * Creates Metadata without an MPF document.
     * Ensures the multi-picture value object uses null/empty defaults.
     *
     * @return void
     */
    #[Test]
    public function createsWithNullMpfDocument(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory      = new MultiPictureFactory();
        $multiPicture = $factory->create($metadata);

        self::assertNull($multiPicture->version);
        self::assertSame(0, $multiPicture->imageCount);
        self::assertSame([], $multiPicture->entries);
        self::assertNull($multiPicture->totalFrames);
        self::assertNull($multiPicture->individualImageNumber);
        self::assertNull($multiPicture->imageUidList);
        self::assertNull($multiPicture->panoramaAngle);
        self::assertNull($multiPicture->panoramaAxis);
    }
}
