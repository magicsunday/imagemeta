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
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies XMP fallback resolution with typed accessors and value normalization.
 */
#[CoversClass(XmpFallbackResolver::class)]
#[UsesClass(ExifXmpMapping::class)]
#[UsesClass(ExifXmpMappingRegistry::class)]
#[UsesClass(XmpDocument::class)]
final class XmpFallbackResolverTest extends TestCase
{
    #[Test]
    public function resolvesIntegerFromXmp(): void
    {
        $xmpDoc   = $this->buildXmpDocument(['http://ns.adobe.com/tiff/1.0/' => ['ImageWidth' => '4000']]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertSame(4000, $resolver->int(ExifTag::IMAGE_WIDTH));
    }

    #[Test]
    public function resolvesFloatFromXmpRational(): void
    {
        $xmpDoc   = $this->buildXmpDocument(['http://ns.adobe.com/exif/1.0/' => ['ExposureTime' => '1/125']]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertEqualsWithDelta(1.0 / 125, $resolver->float(ExifTag::EXPOSURE_TIME), 0.0001);
    }

    #[Test]
    public function resolvesStringFromXmp(): void
    {
        $xmpDoc   = $this->buildXmpDocument(['http://ns.adobe.com/tiff/1.0/' => ['Make' => 'Canon']]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertSame('Canon', $resolver->string(ExifTag::MAKE));
    }

    #[Test]
    public function resolvesExifExNamespaceProperty(): void
    {
        $xmpDoc   = $this->buildXmpDocument(['http://cipa.jp/exif/1.0/' => ['LensModel' => 'RF 50mm F1.8 STM']]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertSame('RF 50mm F1.8 STM', $resolver->string(ExifTag::LENS_MODEL));
    }

    #[Test]
    public function returnsNullForMissingProperty(): void
    {
        $xmpDoc   = $this->buildXmpDocument([]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertNull($resolver->int(ExifTag::IMAGE_WIDTH));
        self::assertNull($resolver->float(ExifTag::EXPOSURE_TIME));
        self::assertNull($resolver->string(ExifTag::MAKE));
    }

    #[Test]
    public function returnsNullForUnmappedTag(): void
    {
        $xmpDoc   = $this->buildXmpDocument([]);
        $resolver = XmpFallbackResolver::fromDocument($xmpDoc);

        self::assertNull($resolver->int(0xFFFF));
    }

    /**
     * @param array<string, array<string, string>> $properties Namespace URI → property name → value.
     */
    private function buildXmpDocument(array $properties): XmpDocument
    {
        $data = [];

        foreach ($properties as $nsUri => $props) {
            foreach ($props as $name => $value) {
                $data['{' . $nsUri . '}' . $name] = $value;
            }
        }

        return new XmpDocument($data);
    }
}
