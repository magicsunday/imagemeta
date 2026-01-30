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

    /**
     * Returns the numeric converter instance.
     *
     * @return NumericConverter
     */
    public function numericConverter(): NumericConverter
    {
        return $this->numericConverter;
    }

    /**
     * Returns the rational converter instance.
     *
     * @return RationalConverter
     */
    public function rationalConverter(): RationalConverter
    {
        return $this->rationalConverter;
    }

    /**
     * Returns the string converter instance.
     *
     * @return StringConverter
     */
    public function stringConverter(): StringConverter
    {
        return $this->stringConverter;
    }

    /**
     * Returns the date/time converter instance.
     *
     * @return DateTimeConverter
     */
    public function dateTimeConverter(): DateTimeConverter
    {
        return $this->dateTimeConverter;
    }

    /**
     * Returns the photo calculator helper.
     *
     * @return PhotoCalculator
     */
    public function photoCalculator(): PhotoCalculator
    {
        return $this->photoCalculator;
    }

    /**
     * Returns the subject area converter instance.
     *
     * @return SubjectAreaConverter
     */
    public function subjectAreaConverter(): SubjectAreaConverter
    {
        return $this->subjectAreaConverter;
    }

    /**
     * Returns the APEX converter instance.
     *
     * @return ApexConverter
     */
    public function apexConverter(): ApexConverter
    {
        return $this->apexConverter;
    }

    /**
     * Returns the flash converter instance.
     *
     * @return FlashConverter
     */
    public function flashConverter(): FlashConverter
    {
        return $this->flashConverter;
    }

    /**
     * Returns the enum converter instance.
     *
     * @return EnumConverter
     */
    public function enumConverter(): EnumConverter
    {
        return $this->enumConverter;
    }

    /**
     * Returns the matrix converter instance.
     *
     * @return MatrixConverter
     */
    public function matrixConverter(): MatrixConverter
    {
        return $this->matrixConverter;
    }

    /**
     * Returns the components converter instance.
     *
     * @return ComponentsConverter
     */
    public function componentsConverter(): ComponentsConverter
    {
        return $this->componentsConverter;
    }

    /**
     * Returns the GPS converter instance.
     *
     * @return GpsConverter
     */
    public function gpsConverter(): GpsConverter
    {
        return $this->gpsConverter;
    }
}
