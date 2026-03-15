<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Reconciliation;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMapping;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMappingRegistry;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpValueType;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the EXIF↔XMP mapping registry covers all CIPA DC-X010-2017 tables.
 */
#[CoversClass(ExifXmpMappingRegistry::class)]
#[UsesClass(ExifXmpMapping::class)]
final class ExifXmpMappingRegistryTest extends TestCase
{
    #[Test]
    public function findsImageWidthMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::IMAGE_WIDTH);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::TIFF, $mapping->xmpNamespace);
        self::assertSame('ImageWidth', $mapping->xmpProperty);
        self::assertSame(ExifXmpValueType::Integer, $mapping->valueType);
    }

    #[Test]
    public function findsMakeMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::MAKE);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::TIFF, $mapping->xmpNamespace);
        self::assertSame('Make', $mapping->xmpProperty);
    }

    #[Test]
    public function findsDateTimeMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::DATETIME);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::XAP, $mapping->xmpNamespace);
        self::assertSame('ModifyDate', $mapping->xmpProperty);
        self::assertSame(ExifXmpValueType::Date, $mapping->valueType);
    }

    #[Test]
    public function findsExposureTimeMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::EXPOSURE_TIME);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::EXIF, $mapping->xmpNamespace);
        self::assertSame('ExposureTime', $mapping->xmpProperty);
        self::assertSame(ExifXmpValueType::Rational, $mapping->valueType);
    }

    #[Test]
    public function findsLensModelInExifExNamespace(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::LENS_MODEL);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::EXIFEX, $mapping->xmpNamespace);
        self::assertSame('LensModel', $mapping->xmpProperty);
    }

    #[Test]
    public function findsPhotographicSensitivityInExifExNamespace(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findByExifTag(ExifTag::PHOTOGRAPHIC_SENSITIVITY);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::EXIFEX, $mapping->xmpNamespace);
        self::assertSame('PhotographicSensitivity', $mapping->xmpProperty);
    }

    #[Test]
    public function findsGpsLatitudeMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findGpsTag(ExifTag::GPS_LATITUDE);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::EXIF, $mapping->xmpNamespace);
        self::assertSame('GPSLatitude', $mapping->xmpProperty);
        self::assertSame(ExifXmpValueType::GpsCoordinate, $mapping->valueType);
    }

    #[Test]
    public function findsInteroperabilityIndexMapping(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();
        $mapping  = $registry->findInteropTag(ExifTag::INTEROPERABILITY_INDEX);

        self::assertInstanceOf(ExifXmpMapping::class, $mapping);
        self::assertSame(XmpNamespace::EXIFEX, $mapping->xmpNamespace);
        self::assertSame('InteroperabilityIndex', $mapping->xmpProperty);
    }

    #[Test]
    public function returnsNullForUnmappedTag(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();

        self::assertNull($registry->findByExifTag(0xFFFF));
    }

    #[Test]
    public function returnsNullForUnmappedGpsTag(): void
    {
        $registry = ExifXmpMappingRegistry::createDefault();

        self::assertNull($registry->findGpsTag(0xFFFF));
    }
}
