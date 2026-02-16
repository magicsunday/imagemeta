<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mb_convert_encoding;
use function strlen;

/**
 * Exercises ParsedExif decoding for the DeviceSettingDescription tag.
 * It validates column/row headers and UTF-16 encoded payload extraction.
 * The tests cover missing tags and undersized payloads that must return null.
 * This ensures device setting descriptions are only exposed when validly encoded.
 */
#[CoversClass(ParsedExif::class)]
#[CoversClass(DeviceSettingDescription::class)]
final class DeviceSettingDescriptionParsingTest extends TestCase
{
    /**
     * Checks that deviceSettingDescription() returns null when the EXIF IFD lacks the tag.
     * Confirms no DeviceSettingDescription is synthesized without tag data.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenTagMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->deviceSettingDescription());
    }

    /**
     * Supplies only two bytes so the header is incomplete and should not be parsed.
     * Ensures the parser enforces the minimum 4-byte columns/rows requirement.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenDataTooShort(): void
    {
        // Only 2 bytes instead of required minimum 4
        $shortData = "\x05\x00";
        $entry     = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: 2,
            value: $shortData,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        self::assertNull($parsedExif->deviceSettingDescription());
    }

    /**
     * Provides little-endian columns and rows with no UTF-16 settings payload.
     * Verifies the description contains the dimensions and an empty settings list.
     *
     * @return void
     */
    #[Test]
    public function parsesLittleEndianWithoutSettings(): void
    {
        // Little-endian: 5 columns (0x05 0x00), 10 rows (0x0A 0x00)
        $data  = "\x05\x00\x0A\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: 4,
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(5, $result->columns);
        self::assertSame(10, $result->rows);
        self::assertSame([], $result->settings);
    }

    /**
     * Provides big-endian columns and rows with no UTF-16 settings payload.
     * Verifies the description decodes dimensions using the TIFF byte order.
     *
     * @return void
     */
    #[Test]
    public function parsesBigEndianWithoutSettings(): void
    {
        // Big-endian: 5 columns (0x00 0x05), 10 rows (0x00 0x0A)
        $data  = "\x00\x05\x00\x0A";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: 4,
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null, byteOrder: Endian::Big);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(5, $result->columns);
        self::assertSame(10, $result->rows);
        self::assertSame([], $result->settings);
    }

    /**
     * Encodes a BOM-framed UTF-16LE "Test" payload after the dimension header.
     * Verifies the decoded settings entry matches the text and dimensions remain intact.
     *
     * @return void
     */
    #[Test]
    public function parsesWithUtf16LESettings(): void
    {
        // Little-endian: 3 columns, 7 rows
        // BOM (FF FE) + UTF-16LE "Test" + NULL terminator
        $data  = "\x03\x00\x07\x00\xFF\xFET\x00e\x00s\x00t\x00\x00\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: strlen($data),
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(3, $result->columns);
        self::assertSame(7, $result->rows);
        self::assertSame(['Test'], $result->settings);
    }

    /**
     * Encodes a BOM-framed UTF-16BE payload after the dimension header.
     * Ensures the settings text is decoded correctly and dimensions are preserved.
     *
     * @return void
     */
    #[Test]
    public function parsesWithUtf16BESettings(): void
    {
        // Little-endian: 3 columns, 7 rows
        // BOM (FE FF) + UTF-16BE "Test" + NULL terminator
        $data  = "\x03\x00\x07\x00\xFE\xFF\x00T\x00e\x00s\x00t\x00\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: strlen($data),
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(3, $result->columns);
        self::assertSame(7, $result->rows);
        self::assertSame(['Test'], $result->settings);
    }

    /**
     * Uses a BOM-framed UTF-16LE settings string with separators and spaces.
     * Confirms the full text is preserved in the settings list with expected dimensions.
     *
     * @return void
     */
    #[Test]
    public function parsesComplexSettings(): void
    {
        // Little-endian: 5 columns, 10 rows
        // BOM (FF FE) + UTF-16LE "ISO:100 WB:Auto"
        $settingsText = 'ISO:100 WB:Auto';
        $utf16le      = mb_convert_encoding($settingsText, 'UTF-16LE', 'UTF-8');
        $data         = "\x05\x00\x0A\x00\xFF\xFE" . $utf16le . "\x00\x00";
        $entry        = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: strlen($data),
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(5, $result->columns);
        self::assertSame(10, $result->rows);
        self::assertSame([$settingsText], $result->settings);
    }

    /**
     * Settings without BOM signature are rejected per EXIF 3.0 §4.6.6.7.45.
     * Returns dimensions with empty settings list.
     *
     * @return void
     */
    #[Test]
    public function rejectsSettingsWithoutBom(): void
    {
        // Little-endian: 3 columns, 7 rows
        // UTF-16LE "Test" WITHOUT BOM
        $data  = "\x03\x00\x07\x00T\x00e\x00s\x00t\x00\x00\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: strlen($data),
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(3, $result->columns);
        self::assertSame(7, $result->rows);
        self::assertSame([], $result->settings);
    }

    /**
     * Uses only a null terminator after the dimension header to represent an empty payload.
     * Ensures the parser returns an empty settings list without failing.
     *
     * @return void
     */
    #[Test]
    public function handlesEmptySettingsGracefully(): void
    {
        // Little-endian: 2 columns, 3 rows
        // Empty UTF-16 string (just null terminator)
        $data  = "\x02\x00\x03\x00\x00\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: 6,
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(2, $result->columns);
        self::assertSame(3, $result->rows);
        self::assertSame([], $result->settings);
    }

    /**
     * Provides two BOM-framed UTF-16LE settings strings separated by null terminators.
     * Verifies the parser splits them into separate entries and keeps the dimensions.
     *
     * @return void
     */
    #[Test]
    public function parsesMultipleSettingsStrings(): void
    {
        $firstSetting  = mb_convert_encoding('ISO:100', 'UTF-16LE', 'UTF-8');
        $secondSetting = mb_convert_encoding('WB:Auto', 'UTF-16LE', 'UTF-8');
        $data          = "\x02\x00\x02\x00"
            . "\xFF\xFE" . $firstSetting . "\x00\x00"
            . "\xFF\xFE" . $secondSetting . "\x00\x00";
        $entry = new IfdEntry(
            tag: ExifTag::DEVICE_SETTING_DESCRIPTION,
            type: 7, // UNDEFINED
            count: strlen($data),
            value: $data,
        );

        $exifIfd    = new Ifd([ExifTag::DEVICE_SETTING_DESCRIPTION => $entry]);
        $parsedExif = new ParsedExif(new Ifd([]), $exifIfd, null, null, null);

        $result = $parsedExif->deviceSettingDescription();

        self::assertInstanceOf(DeviceSettingDescription::class, $result);
        self::assertSame(2, $result->columns);
        self::assertSame(2, $result->rows);
        self::assertSame(['ISO:100', 'WB:Auto'], $result->settings);
    }
}
