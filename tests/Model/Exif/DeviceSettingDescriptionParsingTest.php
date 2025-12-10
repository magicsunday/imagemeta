<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mb_convert_encoding;
use function strlen;

/**
 * Tests for DeviceSettingDescription parsing in ParsedExif.
 *
 * EXIF 3.0 §4.6.6.7.45: This tag indicates information on the picture-taking
 * conditions of a particular camera model. The format consists of:
 * - 2 bytes SHORT: Display columns
 * - 2 bytes SHORT: Display rows
 * - Remaining bytes: Camera settings (Unicode UTF-16, NULL-terminated)
 */
#[CoversClass(ParsedExif::class)]
#[CoversClass(DeviceSettingDescription::class)]
final class DeviceSettingDescriptionParsingTest extends TestCase
{
    #[Test]
    public function returnsNullWhenTagMissing(): void
    {
        $ifd0       = new Ifd([]);
        $parsedExif = new ParsedExif($ifd0, null, null, null, null);

        self::assertNull($parsedExif->deviceSettingDescription());
    }

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

    #[Test]
    public function parsesBigEndianWithoutSettings(): void
    {
        // Big-endian: 5 columns (0x00 0x05), 10 rows (0x00 0x0A)
        // These will initially be read as LE (1280, 2560) which are unreasonable
        // The parser should fall back to BE interpretation
        $data  = "\x00\x05\x00\x0A";
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

    #[Test]
    public function parsesWithUtf16LESettings(): void
    {
        // Little-endian: 3 columns, 7 rows
        // UTF-16LE: "Test" = T(0x54 0x00) e(0x65 0x00) s(0x73 0x00) t(0x74 0x00) \0(0x00 0x00)
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
        self::assertSame(['Test'], $result->settings);
    }

    #[Test]
    public function parsesWithUtf16BESettings(): void
    {
        // Little-endian: 3 columns, 7 rows
        // UTF-16BE: "Test" = T(0x00 0x54) e(0x00 0x65) s(0x00 0x73) t(0x00 0x74) \0(0x00 0x00)
        $data  = "\x03\x00\x07\x00\x00T\x00e\x00s\x00t\x00\x00";
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

    #[Test]
    public function parsesComplexSettings(): void
    {
        // Little-endian: 5 columns, 10 rows
        // UTF-16LE: "ISO:100 WB:Auto"
        $settingsText = 'ISO:100 WB:Auto';
        $utf16le      = mb_convert_encoding($settingsText, 'UTF-16LE', 'UTF-8');
        $data         = "\x05\x00\x0A\x00" . $utf16le . "\x00\x00";
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

    #[Test]
    public function parsesMultipleSettingsStrings(): void
    {
        $firstSetting  = mb_convert_encoding('ISO:100', 'UTF-16LE', 'UTF-8');
        $secondSetting = mb_convert_encoding('WB:Auto', 'UTF-16LE', 'UTF-8');
        $data          = "\x02\x00\x02\x00" . $firstSetting . "\x00\x00" . $secondSetting . "\x00\x00";
        $entry         = new IfdEntry(
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
