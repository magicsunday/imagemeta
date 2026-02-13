<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Tests structured XMP values used for rdf:parseType="Resource" properties.
 */
#[CoversClass(XmpStructuredValue::class)]
final class XmpStructuredValueTest extends TestCase
{
    /**
     * Returns nested values by namespace/local-name pair.
     *
     * @return void
     */
    #[Test]
    public function getReturnsNestedValue(): void
    {
        $namespace = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';
        $value     = new XmpStructuredValue([
            sprintf('{%s}CiEmailWork', $namespace) => 'jane@example.com',
        ]);

        self::assertSame('jane@example.com', $value->get($namespace, 'CiEmailWork'));
    }

    /**
     * Resolves string values from string, list, and language-alternative child properties.
     *
     * @return void
     */
    #[Test]
    public function stringResolvesSupportedChildValueForms(): void
    {
        $namespace = 'http://example.com/ns/';
        $value     = new XmpStructuredValue([
            sprintf('{%s}StringField', $namespace) => '  value  ',
            sprintf('{%s}ListField', $namespace)   => ['  first ', 'second'],
            sprintf('{%s}AltField', $namespace)    => new XmpLanguageAlternative([
                ['lang' => 'x-default', 'value' => '  default  '],
            ]),
        ]);

        self::assertSame('value', $value->string($namespace, 'StringField'));
        self::assertSame('first', $value->string($namespace, 'ListField'));
        self::assertSame('default', $value->string($namespace, 'AltField'));
    }

    /**
     * Merges nested structured values recursively by child key.
     *
     * @return void
     */
    #[Test]
    public function mergeCombinesNestedStructuredValues(): void
    {
        $namespace = 'http://example.com/ns/';
        $first     = new XmpStructuredValue([
            sprintf('{%s}Address', $namespace) => new XmpStructuredValue([
                sprintf('{%s}City', $namespace) => 'Berlin',
            ]),
        ]);
        $second = new XmpStructuredValue([
            sprintf('{%s}Address', $namespace) => new XmpStructuredValue([
                sprintf('{%s}Street', $namespace) => 'Main Street 1',
            ]),
        ]);

        $merged = XmpStructuredValue::merge($first, $second);

        $address = $merged->get($namespace, 'Address');
        self::assertInstanceOf(XmpStructuredValue::class, $address);
        self::assertSame('Berlin', $address->get($namespace, 'City'));
        self::assertSame('Main Street 1', $address->get($namespace, 'Street'));
    }
}
