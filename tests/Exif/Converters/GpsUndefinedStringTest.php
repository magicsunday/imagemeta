<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Converters;

use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\ValidatesGpsRef;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Exercises the 8-byte character code area enforcement for GPS UNDEFINED text fields.
 * It validates that EXIF 3.0 §4.6.4 is enforced for GPSProcessingMethod and GPSAreaInformation.
 * The suite covers missing prefixes and payloads shorter than 8 bytes.
 * This ensures non-conformant GPS text fields are rejected.
 *
 * @internal
 */
#[CoversClass(GpsConverter::class)]
#[CoversClass(GpsTimestampConverter::class)]
#[UsesClass(ValueConverters::class)]
#[UsesTrait(ValidatesGpsRef::class)]
final class GpsUndefinedStringTest extends TestCase
{
    private ValueConverters $converters;

    protected function setUp(): void
    {
        $this->converters = new ValueConverters();
    }

    /**
     * Supplies a GPSProcessingMethod shorter than 8 bytes without a prefix.
     * Confirms that payloads missing the character code area are rejected.
     */
    #[Test]
    public function rejectsProcessingMethodShorterThanEightBytes(): void
    {
        $result = $this->converters->gpsFromIfd($this->gpsIfdWithProcessingMethod('GPS'));

        self::assertNull($result['processing_method']);
    }

    /**
     * Supplies a GPSProcessingMethod with an unrecognised 8-byte prefix.
     * Confirms that an unknown encoding identifier is rejected.
     */
    #[Test]
    public function rejectsProcessingMethodWithUnknownPrefix(): void
    {
        $result = $this->converters->gpsFromIfd($this->gpsIfdWithProcessingMethod("INVALID\0GPS data"));

        self::assertNull($result['processing_method']);
    }

    /**
     * Supplies a GPSProcessingMethod with a valid ASCII prefix.
     * Confirms the text content is extracted correctly.
     */
    #[Test]
    public function acceptsProcessingMethodWithAsciiPrefix(): void
    {
        $result = $this->converters->gpsFromIfd($this->gpsIfdWithProcessingMethod("ASCII\0\0\0NETWORK"));

        self::assertSame('NETWORK', $result['processing_method']);
    }

    /**
     * Supplies a GPSAreaInformation shorter than 8 bytes without a prefix.
     * Confirms that payloads missing the character code area are rejected.
     */
    #[Test]
    public function rejectsAreaInformationShorterThanEightBytes(): void
    {
        $gps = new Ifd([
            ExifTag::GPS_AREA_INFORMATION => new IfdEntry(
                ExifTag::GPS_AREA_INFORMATION,
                7,
                5,
                'Tokyo',
            ),
        ]);

        $result = $this->converters->gpsFromIfd($gps);

        self::assertNull($result['area_information']);
    }

    private function gpsIfdWithProcessingMethod(string $value): Ifd
    {
        return new Ifd([
            ExifTag::GPS_PROCESSING_METHOD => new IfdEntry(
                ExifTag::GPS_PROCESSING_METHOD,
                7,
                strlen($value),
                $value,
            ),
        ]);
    }
}
