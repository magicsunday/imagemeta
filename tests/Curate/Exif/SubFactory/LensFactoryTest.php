<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\LensFactory;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Lens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LensFactory::class)]
final class LensFactoryTest extends TestCase
{
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('lensMake')->willReturn('Canon');
        $exifDoc->method('lensModel')->willReturn('RF 24-70mm F2.8 L IS USM');
        $exifDoc->method('lensSerialNumber')->willReturn('123456789');
        $exifDoc->method('focalLengthMm')->willReturn(50.0);
        $exifDoc->method('focalLength35Mm')->willReturn(50);
        $exifDoc->method('maxApertureApex')->willReturn(3.0);
        $exifDoc->method('lensSpecification')->willReturn([24.0, 70.0, 2.8, 2.8]);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new LensFactory();
        $lens    = $factory->create($metadata);

        self::assertInstanceOf(Lens::class, $lens);
        self::assertSame('Canon', $lens->lensMake);
        self::assertSame('RF 24-70mm F2.8 L IS USM', $lens->lensModel);
        self::assertSame('123456789', $lens->lensSerialNumber);
        self::assertSame(50.0, $lens->focalLengthMm);
        self::assertSame(50, $lens->focalLengthIn35mm);
        self::assertEqualsWithDelta(2.0, $lens->maxApertureFNumber, 0.1);
        self::assertSame([24.0, 70.0, 2.8, 2.8], $lens->lensSpecification);
    }

    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $metadata = new Metadata();

        $factory = new LensFactory();
        $lens    = $factory->create($metadata);

        self::assertInstanceOf(Lens::class, $lens);
        self::assertNull($lens->lensMake);
        self::assertNull($lens->lensModel);
        self::assertNull($lens->lensSerialNumber);
        self::assertNull($lens->focalLengthMm);
        self::assertNull($lens->focalLengthIn35mm);
        self::assertNull($lens->maxApertureFNumber);
    }

    #[Test]
    public function calculatesMaxApertureFromApex(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('lensMake')->willReturn(null);
        $exifDoc->method('lensModel')->willReturn(null);
        $exifDoc->method('lensSerialNumber')->willReturn(null);
        $exifDoc->method('focalLengthMm')->willReturn(null);
        $exifDoc->method('focalLength35Mm')->willReturn(null);
        $exifDoc->method('maxApertureApex')->willReturn(2.0);
        $exifDoc->method('lensSpecification')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new LensFactory();
        $lens    = $factory->create($metadata);

        self::assertInstanceOf(Lens::class, $lens);
        self::assertNotNull($lens->maxApertureFNumber);
        self::assertEqualsWithDelta(1.4, $lens->maxApertureFNumber, 0.1);
    }

    #[Test]
    public function handlesNullMaxApertureApex(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('lensMake')->willReturn('Sony');
        $exifDoc->method('lensModel')->willReturn('FE 24-70mm F2.8 GM');
        $exifDoc->method('lensSerialNumber')->willReturn(null);
        $exifDoc->method('focalLengthMm')->willReturn(35.0);
        $exifDoc->method('focalLength35Mm')->willReturn(35);
        $exifDoc->method('maxApertureApex')->willReturn(null);
        $exifDoc->method('lensSpecification')->willReturn(null);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new LensFactory();
        $lens    = $factory->create($metadata);

        self::assertInstanceOf(Lens::class, $lens);
        self::assertSame('Sony', $lens->lensMake);
        self::assertSame('FE 24-70mm F2.8 GM', $lens->lensModel);
        self::assertNull($lens->maxApertureFNumber);
    }
}
