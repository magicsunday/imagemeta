<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\CameraLensExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ColorSpaceExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DescriptionExifReader;
use MagicSunday\ImageMeta\Exif\Reader\DngMetadataExifReader;
use MagicSunday\ImageMeta\Exif\Reader\ImageStructureExifReader;
use MagicSunday\ImageMeta\Exif\Reader\UserCommentExifReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises miscellaneous EXIF string tags exposed by ParsedExif.
 * It validates ImageUniqueID formatting and camera owner/serial/lens identifiers.
 * The suite covers optional tags and ensures nulls are returned when absent.
 * This keeps less-common EXIF fields consistent and easy to consume.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
#[UsesClass(CameraLensExifReader::class)]
#[UsesClass(ColorSpaceExifReader::class)]
#[UsesClass(DescriptionExifReader::class)]
#[UsesClass(DngMetadataExifReader::class)]
#[UsesClass(ImageStructureExifReader::class)]
#[UsesClass(UserCommentExifReader::class)]
final class ParsedExifOtherTagsTest extends TestCase
{
    /**
     * Supplies the ImageUniqueID tag as a 32-character hex string.
     * Confirms imageUniqueId() returns the same identifier without modification.
     */
    #[Test]
    public function imageUniqueIdReturnsHexUuidString(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(
                ExifTag::IMAGE_UNIQUE_ID,
                2,
                33,
                '00112233445566778899aabbccddeeff',
            ),
        ]);

        self::assertSame('00112233445566778899aabbccddeeff', $parsedExif->imageUniqueId());
    }

    /**
     * Provides an ImageUniqueID value that is too short (only 16 hex characters).
     * Verifies that non-conformant lengths are rejected per EXIF 3.0 §4.6.6.9.1.
     */
    #[Test]
    public function imageUniqueIdRejectsShortHexString(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(
                ExifTag::IMAGE_UNIQUE_ID,
                2,
                17,
                '0011223344556677',
            ),
        ]);

        self::assertNull($parsedExif->imageUniqueId());
    }

    /**
     * Provides an ImageUniqueID value with non-hex characters.
     * Verifies that invalid hex content is rejected per EXIF 3.0 §4.6.6.9.1.
     */
    #[Test]
    public function imageUniqueIdRejectsNonHexCharacters(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::IMAGE_UNIQUE_ID => new IfdEntry(
                ExifTag::IMAGE_UNIQUE_ID,
                2,
                33,
                '00112233445566778899aabbccddeezz',
            ),
        ]);

        self::assertNull($parsedExif->imageUniqueId());
    }

    /**
     * Populates the camera owner, serial, and lens attribution tags.
     * Verifies each getter returns the corresponding EXIF string.
     */
    #[Test]
    public function hardwareAttributionTagsReturnExifStrings(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::CAMERA_OWNER_NAME  => new IfdEntry(ExifTag::CAMERA_OWNER_NAME, 2, 1, 'Owner'),
            ExifTag::BODY_SERIAL_NUMBER => new IfdEntry(ExifTag::BODY_SERIAL_NUMBER, 2, 1, '123456789'),
            ExifTag::LENS_MAKE          => new IfdEntry(ExifTag::LENS_MAKE, 2, 1, 'LensMaker'),
            ExifTag::LENS_MODEL         => new IfdEntry(ExifTag::LENS_MODEL, 2, 1, 'Lens Model 12-35mm'),
            ExifTag::LENS_SERIAL_NUMBER => new IfdEntry(ExifTag::LENS_SERIAL_NUMBER, 2, 1, 'LN987654321'),
        ]);

        self::assertSame('Owner', $parsedExif->ownerName());
        self::assertSame('123456789', $parsedExif->bodySerialNumber());
        self::assertSame('LensMaker', $parsedExif->lensMake());
        self::assertSame('Lens Model 12-35mm', $parsedExif->lensModel());
        self::assertSame('LN987654321', $parsedExif->lensSerialNumber());
    }

    /**
     * Uses a LensSpecification tag with four rational values.
     * Ensures the parser converts them to floats in the expected order.
     */
    #[Test]
    public function lensSpecificationParsesFourRationals(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::LENS_SPECIFICATION => new IfdEntry(
                ExifTag::LENS_SPECIFICATION,
                5,
                4,
                [
                    [24, 1],
                    [70, 1],
                    [28, 10],
                    [28, 10],
                ],
            ),
        ]);

        self::assertSame([24.0, 70.0, 2.8, 2.8], $parsedExif->lensSpecification());
    }

    /**
     * Provides firmware and software pipeline tags with human-readable strings.
     * Confirms ParsedExif surfaces each software field as an EXIF string.
     */
    #[Test]
    public function softwarePipelineTagsReturnExifStrings(): void
    {
        $parsedExif = $this->parsedExifFromExifEntries([
            ExifTag::CAMERA_FIRMWARE           => new IfdEntry(ExifTag::CAMERA_FIRMWARE, 2, 1, 'Firmware 1.2.3'),
            ExifTag::RAW_DEVELOPING_SOFTWARE   => new IfdEntry(ExifTag::RAW_DEVELOPING_SOFTWARE, 2, 1, 'RAW Developer 5.0'),
            ExifTag::IMAGE_EDITING_SOFTWARE    => new IfdEntry(ExifTag::IMAGE_EDITING_SOFTWARE, 2, 1, 'Editor 2.0'),
            ExifTag::METADATA_EDITING_SOFTWARE => new IfdEntry(ExifTag::METADATA_EDITING_SOFTWARE, 2, 1, 'Metadata Tool 3.1'),
        ]);

        self::assertSame('Firmware 1.2.3', $parsedExif->cameraFirmware());
        self::assertSame('RAW Developer 5.0', $parsedExif->rawDevelopingSoftware());
        self::assertSame('Editor 2.0', $parsedExif->imageEditingSoftware());
        self::assertSame('Metadata Tool 3.1', $parsedExif->metadataEditingSoftware());
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromExifEntries(array $exifEntries): ParsedExif
    {
        return new ParsedExif(new Ifd([]), new Ifd($exifEntries), null, null, null);
    }
}
