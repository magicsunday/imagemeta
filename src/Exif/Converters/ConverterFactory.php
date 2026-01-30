<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Converters;

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

    private GpsConverter $gpsConverter;

    public function __construct()
    {
        // Create converters without dependencies first
        $this->stringConverter      = new StringConverter();
        $this->dateTimeConverter    = new DateTimeConverter();
        $this->photoCalculator      = new PhotoCalculator();
        $this->subjectAreaConverter = new SubjectAreaConverter();
        $this->flashConverter       = new FlashConverter();

        // Create NumericConverter with RationalConverter for full functionality
        // Note: We create a temporary NumericConverter first, then use it to create
        // the RationalConverter, and finally create the real NumericConverter with RationalConverter
        $tempNumericConverter    = new NumericConverter();
        $tempRationalConverter   = new RationalConverter($tempNumericConverter);
        $this->numericConverter  = new NumericConverter($tempRationalConverter);
        $this->rationalConverter = new RationalConverter($this->numericConverter);

        // Create converters that depend on RationalConverter
        $this->apexConverter   = new ApexConverter($this->rationalConverter);
        $this->enumConverter   = new EnumConverter($this->rationalConverter);
        $this->matrixConverter = new MatrixConverter($this->rationalConverter);

        // Create ComponentsConverter (depends on NumericConverter)
        $this->componentsConverter = new ComponentsConverter($this->numericConverter);

        // Create GpsConverter (depends on multiple converters)
        $this->gpsConverter = new GpsConverter(
            $this->rationalConverter,
            $this->stringConverter,
            $this->numericConverter,
        );
    }

    public function numericConverter(): NumericConverter
    {
        return $this->numericConverter;
    }

    public function rationalConverter(): RationalConverter
    {
        return $this->rationalConverter;
    }

    public function stringConverter(): StringConverter
    {
        return $this->stringConverter;
    }

    public function dateTimeConverter(): DateTimeConverter
    {
        return $this->dateTimeConverter;
    }

    public function photoCalculator(): PhotoCalculator
    {
        return $this->photoCalculator;
    }

    public function subjectAreaConverter(): SubjectAreaConverter
    {
        return $this->subjectAreaConverter;
    }

    public function apexConverter(): ApexConverter
    {
        return $this->apexConverter;
    }

    public function flashConverter(): FlashConverter
    {
        return $this->flashConverter;
    }

    public function enumConverter(): EnumConverter
    {
        return $this->enumConverter;
    }

    public function matrixConverter(): MatrixConverter
    {
        return $this->matrixConverter;
    }

    public function componentsConverter(): ComponentsConverter
    {
        return $this->componentsConverter;
    }

    public function gpsConverter(): GpsConverter
    {
        return $this->gpsConverter;
    }
}
