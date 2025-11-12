<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests\Scripts;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests XMP enum mapping functionality in the metadata formatter.
 */
#[CoversClass(\MagicSunday\ImageMeta\Scripts\MetadataFormatter::class)]
#[UsesClass(XmpDocument::class)]
final class MetadataFormatterXmpEnumTest extends TestCase
{
    private const string EXIF_NS = 'http://ns.adobe.com/exif/1.0/';
    private const string TIFF_NS = 'http://ns.adobe.com/tiff/1.0/';

    /**
     * Tests that XMP EXIF namespace properties are mapped to enums.
     *
     * Validates that MeteringMode, LightSource, ColorSpace and other EXIF
     * properties in XMP are converted to their enum representations.
     */
    #[Test]
    public function convertXmpValueToEnumConvertsExifNamespaceProperties(): void
    {
        // Create formatter instance
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';
        $formatter = new \MagicSunday\ImageMeta\Scripts\MetadataFormatter();

        // Use reflection to access private method
        $reflection = new ReflectionClass($formatter);
        $method     = $reflection->getMethod('convertXmpValueToEnum');
        $method->setAccessible(true);

        // Test MeteringMode (2 = Center Weighted Average)
        $result = $method->invoke($formatter, self::EXIF_NS, 'MeteringMode', '2');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\MeteringMode::class, $result);
        self::assertSame(2, $result->value);
        self::assertSame('CENTER_WEIGHTED_AVERAGE', $result->name);

        // Test LightSource (0 = Unknown)
        $result = $method->invoke($formatter, self::EXIF_NS, 'LightSource', '0');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\LightSource::class, $result);
        self::assertSame(0, $result->value);
        self::assertSame('UNKNOWN', $result->name);

        // Test ColorSpace (1 = sRGB)
        $result = $method->invoke($formatter, self::EXIF_NS, 'ColorSpace', '1');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\ColorSpace::class, $result);
        self::assertSame(1, $result->value);
        self::assertSame('SRGB', $result->name);

        // Test ExposureProgram (3 = Aperture Priority)
        $result = $method->invoke($formatter, self::EXIF_NS, 'ExposureProgram', '3');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\ExposureProgram::class, $result);
        self::assertSame(3, $result->value);
        self::assertSame('APERTURE_PRIORITY', $result->name);

        // Test Saturation (0 = Normal)
        $result = $method->invoke($formatter, self::EXIF_NS, 'Saturation', '0');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\Saturation::class, $result);
        self::assertSame(0, $result->value);
        self::assertSame('NORMAL', $result->name);

        // Test Sharpness (0 = Normal)
        $result = $method->invoke($formatter, self::EXIF_NS, 'Sharpness', '0');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\Sharpness::class, $result);
        self::assertSame(0, $result->value);
        self::assertSame('NORMAL', $result->name);
    }

    /**
     * Tests that XMP TIFF namespace properties are mapped to enums.
     *
     * Validates that Compression, Orientation, and other TIFF properties
     * in XMP are converted to their enum representations.
     */
    #[Test]
    public function convertXmpValueToEnumConvertsTiffNamespaceProperties(): void
    {
        // Create formatter instance
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';
        $formatter = new \MagicSunday\ImageMeta\Scripts\MetadataFormatter();

        // Use reflection to access private method
        $reflection = new ReflectionClass($formatter);
        $method     = $reflection->getMethod('convertXmpValueToEnum');
        $method->setAccessible(true);

        // Test Compression (6 = JPEG)
        $result = $method->invoke($formatter, self::TIFF_NS, 'Compression', '6');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\Compression::class, $result);
        self::assertSame(6, $result->value);
        self::assertSame('JPEG', $result->name);

        // Test Orientation (1 = Horizontal/Normal)
        $result = $method->invoke($formatter, self::TIFF_NS, 'Orientation', '1');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\Orientation::class, $result);
        self::assertSame(1, $result->value);
        self::assertSame('HORIZONTAL_NORMAL', $result->name);

        // Test ResolutionUnit (2 = Inches)
        $result = $method->invoke($formatter, self::TIFF_NS, 'ResolutionUnit', '2');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\ResolutionUnit::class, $result);
        self::assertSame(2, $result->value);
        self::assertSame('INCHES', $result->name);
    }

    /**
     * Tests that non-enum XMP properties are returned unchanged.
     *
     * Properties without enum mappings should pass through without conversion.
     */
    #[Test]
    public function convertXmpValueToEnumReturnsOriginalValueForNonEnumProperties(): void
    {
        // Create formatter instance
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';
        $formatter = new \MagicSunday\ImageMeta\Scripts\MetadataFormatter();

        // Use reflection to access private method
        $reflection = new ReflectionClass($formatter);
        $method     = $reflection->getMethod('convertXmpValueToEnum');
        $method->setAccessible(true);

        // Test string property without enum mapping
        $result = $method->invoke($formatter, self::TIFF_NS, 'Make', 'SAMSUNG');
        self::assertSame('SAMSUNG', $result);

        // Test string property without enum mapping
        $result = $method->invoke($formatter, self::TIFF_NS, 'Model', 'GT-I9195');
        self::assertSame('GT-I9195', $result);

        // Test numeric string without enum mapping
        $result = $method->invoke($formatter, self::EXIF_NS, 'ExposureTime', '0.020000');
        self::assertSame('0.020000', $result);
    }

    /**
     * Tests that array values are handled correctly for enum conversion.
     *
     * When XMP properties contain arrays, the first element should be used
     * for enum conversion.
     */
    #[Test]
    public function convertXmpValueToEnumHandlesArrayValues(): void
    {
        // Create formatter instance
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';
        $formatter = new \MagicSunday\ImageMeta\Scripts\MetadataFormatter();

        // Use reflection to access private method
        $reflection = new ReflectionClass($formatter);
        $method     = $reflection->getMethod('convertXmpValueToEnum');
        $method->setAccessible(true);

        // Test array value (should use first element)
        $result = $method->invoke($formatter, self::EXIF_NS, 'MeteringMode', ['2', '3']);
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\MeteringMode::class, $result);
        self::assertSame(2, $result->value);
        self::assertSame('CENTER_WEIGHTED_AVERAGE', $result->name);
    }

    /**
     * Tests that numeric string values are correctly parsed for enum matching.
     *
     * XMP stores all values as strings, so numeric strings need to be
     * converted to int/float for proper enum matching.
     */
    #[Test]
    public function convertXmpValueToEnumParsesNumericStrings(): void
    {
        // Create formatter instance
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';
        $formatter = new \MagicSunday\ImageMeta\Scripts\MetadataFormatter();

        // Use reflection to access private method
        $reflection = new ReflectionClass($formatter);
        $method     = $reflection->getMethod('convertXmpValueToEnum');
        $method->setAccessible(true);

        // Test integer as string
        $result = $method->invoke($formatter, self::EXIF_NS, 'MeteringMode', '2');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\MeteringMode::class, $result);

        // Test float as string  
        $result = $method->invoke($formatter, self::EXIF_NS, 'WhiteBalance', '0.0');
        self::assertInstanceOf(\MagicSunday\ImageMeta\Value\Enum\WhiteBalance::class, $result);
        self::assertSame(0, $result->value);
    }
}
