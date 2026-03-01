<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Mpf;

use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Value\Enum\MpImageDataFormat;
use MagicSunday\ImageMeta\Value\Enum\MpImageType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the MpfEntry value object for MP Index IFD entries.
 * It verifies construction and property access for MPF image entries.
 */
#[CoversClass(MpfEntry::class)]
#[UsesClass(MpImageDataFormat::class)]
#[UsesClass(MpImageType::class)]
final class MpfEntryTest extends TestCase
{
    /**
     * Constructs an MPF entry and verifies all properties are preserved.
     */
    #[Test]
    public function constructionPreservesProperties(): void
    {
        $entry = new MpfEntry(
            attributes: 0x030000,
            imageSize: 2048576,
            dataOffset: 1024,
            dependentImage1: 0,
            dependentImage2: 0,
            isDependentParent: false,
            isDependentChild: false,
            isRepresentativeImage: false,
            imageType: MpImageType::BaselinePrimaryImage,
            imageDataFormat: MpImageDataFormat::Jpeg,
        );

        self::assertSame(0x030000, $entry->attributes);
        self::assertSame(2048576, $entry->imageSize);
        self::assertSame(1024, $entry->dataOffset);
        self::assertSame(0, $entry->dependentImage1);
        self::assertSame(0, $entry->dependentImage2);
        self::assertFalse($entry->isDependentParent);
        self::assertFalse($entry->isDependentChild);
        self::assertFalse($entry->isRepresentativeImage);
        self::assertSame(MpImageType::BaselinePrimaryImage, $entry->imageType);
        self::assertSame(MpImageDataFormat::Jpeg, $entry->imageDataFormat);
    }

    /**
     * Accepts zero values for all integer fields.
     * The first entry in the MP Index typically has a zero data offset.
     */
    #[Test]
    public function acceptsZeroValues(): void
    {
        $entry = new MpfEntry(
            attributes: 0,
            imageSize: 0,
            dataOffset: 0,
            dependentImage1: 0,
            dependentImage2: 0,
            isDependentParent: false,
            isDependentChild: false,
            isRepresentativeImage: false,
            imageType: MpImageType::Undefined,
            imageDataFormat: MpImageDataFormat::Jpeg,
        );

        self::assertSame(0, $entry->attributes);
        self::assertSame(0, $entry->imageSize);
        self::assertSame(0, $entry->dataOffset);
        self::assertSame(0, $entry->dependentImage1);
        self::assertSame(0, $entry->dependentImage2);
        self::assertFalse($entry->isDependentParent);
        self::assertFalse($entry->isDependentChild);
        self::assertFalse($entry->isRepresentativeImage);
        self::assertSame(MpImageType::Undefined, $entry->imageType);
        self::assertSame(MpImageDataFormat::Jpeg, $entry->imageDataFormat);
    }

    /**
     * Constructs an entry with flags and null enum values for unknown codes.
     */
    #[Test]
    public function preservesFlagsAndNullEnums(): void
    {
        $entry = new MpfEntry(
            attributes: 0xE0FF0007,
            imageSize: 100,
            dataOffset: 200,
            dependentImage1: 1,
            dependentImage2: 2,
            isDependentParent: true,
            isDependentChild: true,
            isRepresentativeImage: true,
            imageType: null,
            imageDataFormat: null,
        );

        self::assertTrue($entry->isDependentParent);
        self::assertTrue($entry->isDependentChild);
        self::assertTrue($entry->isRepresentativeImage);
        self::assertNull($entry->imageType);
        self::assertNull($entry->imageDataFormat);
    }
}
