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
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Value\FlashInfo;

/**
 * Facade that provides a single-dependency entry point for EXIF value conversion.
 *
 * EXIF 3.0 §4.6 and Annex C define the semantic interpretation of the tag
 * payloads normalized by these converters.
 *
 * Each public method delegates to a specific converter from the
 * MagicSunday\ImageMeta\Exif\Converters namespace. This indirection is a
 * deliberate design tradeoff: 14 consumer classes inject one ValueConverters
 * instance instead of depending on 10+ individual converter classes, keeping
 * constructor signatures small and shielding consumers from converter
 * refactorings.
 *
 * @phpstan-type RationalComponent = array<int, int|float|string>
 * @phpstan-type RationalLike = array<int, RationalComponent|ExifRational|int|float|string>
 * @phpstan-type ExifScalar int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
 *
 * @phpstan-import-type GpsFieldMap from GpsConverter
 */
final readonly class ValueConverters
{
    public function __construct(private ConverterFactory $factory = new ConverterFactory())
    {
    }

    /**
     * Converts a TIFF RATIONAL or scalar value into a floating point value.
     *
     * @param int|float|string|array<int, int|float|string|UInt64>|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The value to convert.
     */
    public function rationalToFloat(
        int|float|string|array|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->factory->rationalConverter->toFloat($value);
    }

    /**
     * Converts a SRATIONAL[3] list into a three-element float vector.
     *
     * @param ExifRationalList $value List containing exactly three SRATIONAL values.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function srationalTripletToFloatVector(ExifRationalList $value): ?array
    {
        return $this->factory->rationalConverter->tripletToFloatVector($value);
    }

    /**
     * Normalizes EXIF subject area representations into a rectangle map.
     *
     * @param array<int, int|float|string> $values Subject area values as extracted from metadata.
     *
     * @return array{x:int,y:int,w:int|null,h:int|null}|null
     */
    public function subjectAreaToRect(array $values): ?array
    {
        return $this->factory->subjectAreaConverter->toRect($values);
    }

    /**
     * Converts a rational pair into a white point array.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float}|null
     */
    public function toWhitePoint(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        return $this->factory->matrixConverter->toWhitePoint($rational);
    }

    /**
     * Converts rational chromaticity pairs into a flat float array.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $rational
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function toPrimaryChromaticities(ExifRationalList|ExifNumericList|array|null $rational): ?array
    {
        return $this->factory->matrixConverter->toPrimaryChromaticities($rational);
    }

    /**
     * Serialises a DNG matrix or CFA pattern into a reproducible string representation.
     *
     * @param array<int, int|float|string|array<int, int|float|string>>|ExifRationalList|ExifNumericList|null $matrix
     */
    public function dngMatrixToString(ExifRationalList|ExifNumericList|array|null $matrix): ?string
    {
        return $this->factory->matrixConverter->dngMatrixToString($matrix);
    }

    /**
     * Converts a textual YCbCr subsampling representation into integer pairs.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSamplingToPair(?string $val): ?array
    {
        return $this->factory->componentsConverter->ycbcrSubSamplingToPair($val);
    }

    /**
     * Normalizes a raw EXIF version byte string into a dotted decimal representation.
     */
    public function toExifVersion(?string $bytes): ?string
    {
        return $this->factory->stringConverter->toExifVersion($bytes);
    }

    /**
     * Attempts to map a raw value to a backed enum instance.
     *
     * @template T of BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T|null
     */
    public function toEnumOrNull(string $enumClass, int|string|null $raw): ?BackedEnum
    {
        return $this->factory->enumConverter->toEnumOrNull($enumClass, $raw);
    }

    /**
     * Converts the maker note safety flag into a boolean representation.
     *
     * @param ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value Raw maker note safety value.
     */
    public function makerNoteSafety(
        ExifNumericList|ExifRationalList|ExifRational|int|float|string|null $value,
    ): ?bool {
        return $this->factory->enumConverter->makerNoteSafety($value);
    }

    /**
     * Converts a stored APEX aperture value into a traditional f-number.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to convert.
     */
    public function apexToFNumber(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->factory->apexConverter->toFNumber($value);
    }

    /**
     * Converts an APEX shutter speed value into seconds.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to convert.
     */
    public function apexShutterSpeedToSeconds(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->factory->apexConverter->toSeconds($value);
    }

    /**
     * Formats an APEX shutter speed value as a human-readable fraction.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public function formatShutterSpeedFromApex(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return $this->factory->apexConverter->formatShutterSpeed($value);
    }

    /**
     * Formats exposure time in seconds as a human-readable string.
     *
     * @param float|null $seconds Exposure time in seconds.
     */
    public function formatExposureTime(?float $seconds): ?string
    {
        return $this->factory->apexConverter->formatExposureTime($seconds);
    }

    /**
     * Formats an APEX aperture value as a human-readable f-number string.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public function formatApertureFromApex(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return $this->factory->apexConverter->formatAperture($value);
    }

    /**
     * Formats an APEX brightness value as a human-readable EV string.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The APEX value to format.
     */
    public function formatBrightnessValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?string {
        return $this->factory->apexConverter->formatBrightness($value);
    }

    /**
     * Calculates the exposure value normalized to ISO 100.
     */
    public function calcEv100(?float $exposureTimeSec, ?float $fNumber, ?int $iso): ?float
    {
        return $this->factory->apexConverter->calcEv100($exposureTimeSec, $fNumber, $iso);
    }

    /**
     * Calculates the hyperfocal distance in metres using the thin lens approximation.
     */
    public function calcHyperfocalM(?float $focalLengthMm, ?float $fNumber, ?float $circleOfConfusionMm): ?float
    {
        return $this->factory->photoCalculator->calcHyperfocalM($focalLengthMm, $fNumber, $circleOfConfusionMm);
    }

    /**
     * Calculates the crop factor from focal lengths.
     */
    public function calcCropFactor(?int $focalLength35mm, ?float $focalLengthMm): ?float
    {
        return $this->factory->photoCalculator->calcCropFactor($focalLength35mm, $focalLengthMm);
    }

    /**
     * Calculates the circle of confusion in millimetres based on the crop factor.
     */
    public function calcCircleOfConfusionMm(?float $cropFactor): ?float
    {
        return $this->factory->photoCalculator->calcCircleOfConfusionMm($cropFactor);
    }

    /**
     * Approximates the diagonal field of view in degrees.
     */
    public function calcFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return $this->factory->photoCalculator->calcFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Approximates the horizontal field of view in degrees.
     */
    public function calcHorizontalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return $this->factory->photoCalculator->calcHorizontalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Approximates the vertical field of view in degrees.
     */
    public function calcVerticalFovDeg(?int $focalLength35mm, ?float $cropFactor, ?float $focalLengthMm = null): ?float
    {
        return $this->factory->photoCalculator->calcVerticalFovDeg($focalLength35mm, $cropFactor, $focalLengthMm);
    }

    /**
     * Decodes the spatial frequency response payload.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public function decodeSpatialFrequencyResponse(?string $payload, Endian $endian = Endian::Big): ?array
    {
        return $this->factory->matrixConverter->decodeSpatialFrequencyResponse($payload, $endian);
    }

    /**
     * Decodes the opto-electronic conversion function payload.
     *
     * @param string|null $payload Raw UNDEFINED payload captured from the EXIF tag.
     * @param Endian      $endian  TIFF byte order of the enclosing EXIF document.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public function decodeOecf(?string $payload, Endian $endian = Endian::Big): ?array
    {
        return $this->factory->matrixConverter->decodeOecf($payload, $endian);
    }

    /**
     * Normalizes the components configuration tag into a list of component identifiers.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value representation.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        return $this->factory->componentsConverter->configuration($value);
    }

    /**
     * Formats a components configuration payload into human readable channel labels.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?array {
        return $this->factory->componentsConverter->configurationLabels($value);
    }

    /**
     * Returns a human readable description for the components configuration.
     *
     * @param array<int, int|float|string|UInt64>|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value Raw EXIF value.
     */
    public function componentsConfigurationDescription(
        array|ExifNumericList|ExifRationalList|ExifRational|UInt64|string|int|float|null $value,
    ): ?string {
        return $this->factory->componentsConverter->configurationDescription($value);
    }

    /**
     * Converts a GPS speed measurement into metres per second.
     *
     * @param string|null                                                                $ref   Speed reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public function gpsSpeedToMs(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->factory->gpsUnitConverter->speedToMs($ref, $value);
    }

    /**
     * Converts a GPS destination distance to metres based on the reference unit.
     *
     * @param string|null                                                                $ref   Distance reference (K, M or N).
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value The measured value.
     */
    public function gpsDistanceToMetres(
        ?string $ref,
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?float {
        return $this->factory->gpsUnitConverter->distanceToMetres($ref, $value);
    }

    /**
     * Normalizes a compass bearing to the [0, 360) interval.
     */
    public function normalizeBearing(int|float|null $value): ?float
    {
        return $this->factory->gpsDirectionConverter->normalizeBearing($value);
    }

    /**
     * Converts the EXIF flash bit field into a typed value object.
     *
     * @param int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value Flash tag value representation.
     */
    public function flashFromShort(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|null $value,
    ): ?FlashInfo {
        return $this->factory->flashConverter->fromShort($value);
    }

    /**
     * Normalizes EXIF offset time values to a canonical "+HH:MM" representation.
     *
     * @param int|float|string|ExifRational|ExifRationalList|null $value The raw offset value.
     */
    public function parseOffsetString(int|float|string|ExifRational|ExifRationalList|null $value): ?string
    {
        return $this->factory->dateTimeConverter->parseOffsetString($value);
    }

    /**
     * Parses an ISO 8601 offset into a DateTimeZone instance.
     */
    public function parseOffset(?string $offset): ?DateTimeZone
    {
        return $this->factory->dateTimeConverter->parseOffset($offset);
    }

    /**
     * Returns the default GPS metadata structure with all keys initialised to null.
     *
     * @return GpsFieldMap
     */
    public function emptyGpsResult(): array
    {
        return $this->factory->gpsConverter->emptyGpsResult();
    }

    /**
     * Extracts GPS metadata including position, navigation and timing information from an IFD.
     *
     * @param Ifd $gps The GPS IFD containing coordinate tags.
     *
     * @return GpsFieldMap
     */
    public function gpsFromIfd(Ifd $gps): array
    {
        return $this->factory->gpsConverter->fromIfd($gps);
    }
}
