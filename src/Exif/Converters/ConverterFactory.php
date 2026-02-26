<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;

/**
 * Factory for creating converter instances with their dependencies properly wired.
 *
 * This factory provides a convenient way to instantiate the converter classes
 * with dependency injection, handling the dependency graph automatically.
 */
final readonly class ConverterFactory
{
    private NumericConverter $numericConverter;

    private RationalConverter $rationalConverter;

    private StringConverter $stringConverter;

    private DateTimeConverter $dateTimeConverter;

    private PhotoCalculator $photoCalculator;

    private SubjectAreaConverter $subjectAreaConverter;

    private ApexConverter $apexConverter;

    private FlashConverter $flashConverter;

    private EnumConverter $enumConverter;

    private MatrixConverter $matrixConverter;

    private ComponentsConverter $componentsConverter;

    private GpsCoordinateConverter $gpsCoordinateConverter;

    private GpsUnitConverter $gpsUnitConverter;

    private GpsDirectionConverter $gpsDirectionConverter;

    private GpsTimestampConverter $gpsTimestampConverter;

    private GpsConverter $gpsConverter;

    /**
     * Builds the converter graph with the required dependencies wired.
     */
    public function __construct()
    {
        // Create converters without dependencies first
        $this->stringConverter      = new StringConverter();
        $this->dateTimeConverter    = new DateTimeConverter();
        $this->photoCalculator      = new PhotoCalculator();
        $this->subjectAreaConverter = new SubjectAreaConverter();
        $this->flashConverter       = new FlashConverter();

        // Break circular dependency via lazy closure: NumericConverter receives
        // a callback that delegates to RationalConverter once the graph is complete.
        $rationalRef            = null;
        $this->numericConverter = new NumericConverter(
            static function (
                int|float|string|array|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
            ) use (&$rationalRef): ?float {
                /** @var RationalConverter $rationalRef */
                return $rationalRef->toFloat($value);
            },
        );
        $this->rationalConverter = new RationalConverter($this->numericConverter);
        $rationalRef             = $this->rationalConverter;

        // Create converters that depend on RationalConverter
        $this->apexConverter   = new ApexConverter($this->rationalConverter);
        $this->enumConverter   = new EnumConverter($this->rationalConverter);
        $this->matrixConverter = new MatrixConverter($this->rationalConverter);

        // Create ComponentsConverter (depends on NumericConverter)
        $this->componentsConverter = new ComponentsConverter($this->numericConverter);

        // Create GPS sub-converters
        $this->gpsCoordinateConverter = new GpsCoordinateConverter($this->rationalConverter, $this->numericConverter);
        $this->gpsUnitConverter       = new GpsUnitConverter($this->rationalConverter);
        $this->gpsDirectionConverter  = new GpsDirectionConverter($this->rationalConverter);
        $this->gpsTimestampConverter  = new GpsTimestampConverter($this->rationalConverter, $this->stringConverter);

        // Create GpsConverter orchestrator (depends on sub-converters)
        $this->gpsConverter = new GpsConverter(
            $this->gpsCoordinateConverter,
            $this->gpsUnitConverter,
            $this->gpsDirectionConverter,
            $this->gpsTimestampConverter,
            $this->rationalConverter,
            $this->stringConverter,
        );
    }

    /**
     * Returns the numeric converter instance.
     */
    public function numericConverter(): NumericConverter
    {
        return $this->numericConverter;
    }

    /**
     * Returns the rational converter instance.
     */
    public function rationalConverter(): RationalConverter
    {
        return $this->rationalConverter;
    }

    /**
     * Returns the string converter instance.
     */
    public function stringConverter(): StringConverter
    {
        return $this->stringConverter;
    }

    /**
     * Returns the date/time converter instance.
     */
    public function dateTimeConverter(): DateTimeConverter
    {
        return $this->dateTimeConverter;
    }

    /**
     * Returns the photo calculator helper.
     */
    public function photoCalculator(): PhotoCalculator
    {
        return $this->photoCalculator;
    }

    /**
     * Returns the subject area converter instance.
     */
    public function subjectAreaConverter(): SubjectAreaConverter
    {
        return $this->subjectAreaConverter;
    }

    /**
     * Returns the APEX converter instance.
     */
    public function apexConverter(): ApexConverter
    {
        return $this->apexConverter;
    }

    /**
     * Returns the flash converter instance.
     */
    public function flashConverter(): FlashConverter
    {
        return $this->flashConverter;
    }

    /**
     * Returns the enum converter instance.
     */
    public function enumConverter(): EnumConverter
    {
        return $this->enumConverter;
    }

    /**
     * Returns the matrix converter instance.
     */
    public function matrixConverter(): MatrixConverter
    {
        return $this->matrixConverter;
    }

    /**
     * Returns the components converter instance.
     */
    public function componentsConverter(): ComponentsConverter
    {
        return $this->componentsConverter;
    }

    /**
     * Returns the GPS unit converter instance.
     */
    public function gpsUnitConverter(): GpsUnitConverter
    {
        return $this->gpsUnitConverter;
    }

    /**
     * Returns the GPS direction converter instance.
     */
    public function gpsDirectionConverter(): GpsDirectionConverter
    {
        return $this->gpsDirectionConverter;
    }

    /**
     * Returns the GPS converter instance.
     */
    public function gpsConverter(): GpsConverter
    {
        return $this->gpsConverter;
    }
}
