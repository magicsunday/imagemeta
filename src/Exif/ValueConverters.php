<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif;

use BackedEnum;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Value\FlashInfo;

/**
 * Helper methods that translate EXIF/TIFF values into PHP friendly scalars.
 *
 * EXIF 3.0 §4.6 and Annex C define the semantic interpretation of the tag
 * payloads normalised by these converters.
 *
 * This class delegates to the specific converter classes in
 * MagicSunday\ImageMeta\Model\Exif\Converters namespace.
 *
 * @phpstan-type RationalComponent = array<int, int|float|string>
 * @phpstan-type RationalLike = array<int, RationalComponent|ExifRational|int|float|string>
 * @phpstan-type ExifScalar int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
 *
 * @phpstan-import-type GpsFieldMap from GpsConverter
 */
final class ValueConverters
{
    private static ?ConverterFactory $factory = null;

    private static function factory(): ConverterFactory
    {
        if (!self::$factory instanceof ConverterFactory) {
            self::$factory = new ConverterFactory();
        }

        return self::$factory;
    }

    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param int|float|string|array<int, int|float|string|UInt64>|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The value to convert.
     *
     * @return float|null
     */
    public static function rationalToFloat(
        int|float|string|array|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return self::factory()->rationalConverter()->toFloat($value);
    }

    /**
     * Converts a SRATIONAL[3] list into a three-element float vector.
     *
     * @param ExifRationalList $value List containing exactly three SRATIONAL values.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public static function srationalTripletToFloatVector(ExifRationalList $value): ?array
    {
        return self::factory()->rationalConverter()->tripletToFloatVector($value);
    }

    /**
     * Normalises EXIF subject area representations into a rectangle map.
     *
     * @param array<int, int|float|string> $values Subject area values as extracted from metadata.
     *
     * @return array{x:int,y:int,w:int|null,h:int|null}|null
     */
    public static function subjectAreaToRect(array $values): ?array
    {
        return self::factory()->subjectAreaConverter()->toRect($values);
    }

    /**
     * Converts a rational pair into a white point array.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float}|null
     */
    public static function toWhitePoint(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        return self::factory()->matrixConverter()->toWhitePoint($rational);
    }

    /**
     * Converts rational chromaticity pairs into a flat float array.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public static function toPrimaryChromaticities(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        return self::factory()->matrixConverter()->toPrimaryChromaticities($rational);
    }

    /**
     * Serialises a DNG matrix or CFA pattern into a reproducible string representation.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $matrix
     */
    public static function dngMatrixToString(ExifRationalList|ExifNumericList|array|null $matrix): ?string
    {
        return self::factory()->matrixConverter()->dngMatrixToString($matrix);
    }

    /**
     * Converts a textual YCbCr subsampling representation into integer pairs.
     *
     * @return array{0:int,1:int}|null
     */
    public static function ycbcrSubSamplingToPair(?string $val): ?array
    {
        return self::factory()->componentsConverter()->ycbcrSubSamplingToPair($val);
    }

    /**
     * Normalises a raw EXIF version byte string into a dotted decimal representation.
     */
    public static function toExifVersion(?string $bytes): ?string
    {
        return self::factory()->stringConverter()->toExifVersion($bytes);
    }

    /**
     * Attempts to map a raw value to a backed enum instance.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     * @param int|string|null $raw
     *
     * @return T|null
     */
    public static function toEnumOrNull(string $enumClass, int|string|null $raw): ?BackedEnum
    {
        return self::factory()->enumConverter()->toEnumOrNull($enumClass, $raw);
    }

    /**
     * Converts the maker note safety flag into a boolean representation.
     *
     * @param ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value Raw maker note safety value.
     */
    public static function makerNoteSafety(
        ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value,
    ): ?bool {
        return self::factory()->enumConverter()->makerNoteSafety($value);
    }

    /**
     * Converts a stored APEX aperture value into a traditional f-number.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to convert.
     */
    public static function apexToFNumber(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return self::factory()->apexConverter()->toFNumber($value);
    }

    /**
     * Converts an APEX shutter speed value into seconds.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to convert.
     */
    public static function apexShutterSpeedToSeconds(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return self::factory()->apexConverter()->toSeconds($value);
    }

    /**
     * Formats an APEX shutter speed value as a human-readable fraction.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public static function formatShutterSpeedFromApex(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return self::factory()->apexConverter()->formatShutterSpeed($value);
    }

    /**
     * Formats exposure time in seconds as a human-readable string.
     *
     * @param float|null $seconds Exposure time in seconds.
     */
    public static function formatExposureTime(?float $seconds): ?string
    {
        return self::factory()->apexConverter()->formatExposureTime($seconds);
    }

    /**
     * Formats an APEX aperture value as a human-readable f-number string.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public static function formatApertureFromApex(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return self::factory()->apexConverter()->formatAperture($value);
    }

    /**
     * Formats an f-number as a human-readable string.
     *
     * @param float|null $fNumber The f-number to format.
     */
    public static function formatFNumber(?float $fNumber): ?string
    {
        return self::factory()->apexConverter()->formatFNumber($fNumber);
    }

    /**
     * Formats an APEX brightness value as a human-readable EV string.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public static function formatBrightnessValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return self::factory()->apexConverter()->formatBrightness($value);
    }

    /**
     * Calculates the exposure value normalised to ISO 100.
     */
    public static function calcEv100(?float $exposureTimeSec, ?float $fNumber, ?int $iso): ?float
    {
        return self::factory()->apexConverter()->calcEv100($exposureTimeSec, $fNumber, $iso);
    }

    /**
     * Calculates the hyperfocal distance in metres using the thin lens approximation.
     */
    public static function calcHyperfocalM(?float $focalLengthMm, ?float $fNumber, ?float $circleOfConfusionMm): ?float
    {
        return self::factory()->photoCalculator()->calcHyperfocalM($focalLengthMm, $fNumber, $circleOfConfusionMm);
    }

    /**
     * Calculates the crop factor from focal lengths.
     */
    public static function calcCropFactor(?int $focalLength35mm, ?float $focalLengthMm): ?float
    {
        return self::factory()->photoCalculator()->calcCropFactor($focalLength35mm, $focalLengthMm);
    }

    /**
     * Calculates the circle of confusion in millimetres based on the crop factor.
     */
    public static function calcCircleOfConfusionMm(?float $cropFactor): ?float
    {
        return self::factory()->photoCalculator()->calcCircleOfConfusionMm($cropFactor);
    }

    /**
     * Approximates the diagonal field of view in degrees.
     */
    public static function calcFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return self::factory()->photoCalculator()->calcFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Approximates the horizontal field of view in degrees.
     */
    public static function calcHorizontalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return self::factory()->photoCalculator()->calcHorizontalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Approximates the vertical field of view in degrees.
     */
    public static function calcVerticalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return self::factory()->photoCalculator()->calcVerticalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Decodes the spatial frequency response payload.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public static function decodeSpatialFrequencyResponse(?string $payload): ?array
    {
        return self::factory()->matrixConverter()->decodeSpatialFrequencyResponse($payload);
    }

    /**
     * Decodes the opto-electronic conversion function payload.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public static function decodeOecf(?string $payload): ?array
    {
        return self::factory()->matrixConverter()->decodeOecf($payload);
    }

    /**
     * Normalises the components configuration tag into a list of component identifiers.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value representation.
     *
     * @return list<int>|null
     */
    public static function componentsConfiguration(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        return self::factory()->componentsConverter()->configuration($value);
    }

    /**
     * Formats a components configuration payload into human readable channel labels.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     *
     * @return list<string>|null
     */
    public static function componentsConfigurationLabels(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        return self::factory()->componentsConverter()->configurationLabels($value);
    }

    /**
     * Returns a human readable description for the components configuration.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     */
    public static function componentsConfigurationDescription(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?string {
        return self::factory()->componentsConverter()->configurationDescription($value);
    }

    /**
     * Converts a GPS speed measurement into metres per second.
     *
     * @param string|null                                                                $ref   Speed reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public static function gpsSpeedToMs(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return self::factory()->gpsConverter()->speedToMs($ref, $value);
    }

    /**
     * Converts a GPS destination distance to metres based on the reference unit.
     *
     * @param string|null                                                                $ref   Distance reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public static function gpsDistanceToMetres(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return self::factory()->gpsConverter()->distanceToMetres($ref, $value);
    }

    /**
     * Normalises a compass bearing to the [0, 360) interval.
     */
    public static function normalizeBearing(int|float|null $value): ?float
    {
        return self::factory()->gpsConverter()->normalizeBearing($value);
    }

    /**
     * Converts the EXIF flash bit field into a typed value object.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Flash tag value representation.
     */
    public static function flashFromShort(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?FlashInfo {
        return self::factory()->flashConverter()->fromShort($value);
    }

    /**
     * Normalises EXIF offset time values to a canonical "+HH:MM" representation.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw offset value.
     */
    public static function parseOffsetString(int|float|string|ExifRational|ExifRationalList|null $value): ?string
    {
        return self::factory()->dateTimeConverter()->parseOffsetString($value);
    }

    /**
     * Parses an ISO 8601 offset into a DateTimeZone instance.
     */
    public static function parseOffset(?string $offset): ?DateTimeZone
    {
        return self::factory()->dateTimeConverter()->parseOffset($offset);
    }

    /**
     * Converts an EXIF offset time value to minutes relative to UTC.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw offset value.
     */
    public static function offsetToMinutes(int|float|string|ExifRational|ExifRationalList|null $value): ?int
    {
        return self::factory()->dateTimeConverter()->offsetToMinutes($value);
    }

    /**
     * Returns the default GPS metadata structure with all keys initialised to null.
     *
     * @return GpsFieldMap
     */
    public static function emptyGpsResult(): array
    {
        return self::factory()->gpsConverter()->emptyGpsResult();
    }

    /**
     * Extracts GPS metadata including position, navigation and timing information from an IFD.
     *
     * @param Ifd $gps The GPS IFD containing coordinate tags.
     *
     * @return GpsFieldMap
     */
    public static function gpsFromIfd(Ifd $gps): array
    {
        return self::factory()->gpsConverter()->fromIfd($gps);
    }
}
