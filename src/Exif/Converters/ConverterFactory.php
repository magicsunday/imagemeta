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
    public NumericConverter $numericConverter;

    public RationalConverter $rationalConverter;

    public StringConverter $stringConverter;

    public DateTimeConverter $dateTimeConverter;

    public PhotoCalculator $photoCalculator;

    public SubjectAreaConverter $subjectAreaConverter;

    public ApexConverter $apexConverter;

    public FlashConverter $flashConverter;

    public EnumConverter $enumConverter;

    public MatrixConverter $matrixConverter;

    public ComponentsConverter $componentsConverter;

    public GpsUnitConverter $gpsUnitConverter;

    public GpsDirectionConverter $gpsDirectionConverter;

    public GpsConverter $gpsConverter;

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
        $gpsCoordinateConverter      = new GpsCoordinateConverter($this->rationalConverter, $this->numericConverter);
        $this->gpsUnitConverter      = new GpsUnitConverter($this->rationalConverter);
        $this->gpsDirectionConverter = new GpsDirectionConverter($this->rationalConverter);
        $gpsTimestampConverter       = new GpsTimestampConverter($this->rationalConverter, $this->stringConverter);

        // Create GpsConverter orchestrator (depends on sub-converters)
        $this->gpsConverter = new GpsConverter(
            $gpsCoordinateConverter,
            $this->gpsUnitConverter,
            $this->gpsDirectionConverter,
            $gpsTimestampConverter,
            $this->rationalConverter,
            $this->stringConverter,
        );
    }
}
