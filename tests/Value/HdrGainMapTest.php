<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\HdrGainMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the HdrGainMap value object stores all HDR gain map properties.
 * This ensures typed access to Adobe hdrgm and Apple apdi XMP namespace values.
 *
 * @internal
 */
#[CoversClass(HdrGainMap::class)]
final class HdrGainMapTest extends TestCase
{
    /**
     * Constructs an HdrGainMap with all properties populated.
     * Verifies each property is accessible and holds the expected value.
     */
    #[Test]
    public function storesAllProperties(): void
    {
        $gainMap = new HdrGainMap(
            hasGainMap: true,
            version: '1.0',
            baseRenditionIsHdr: false,
            hdrCapacityMin: 0.0,
            hdrCapacityMax: 3.5,
            gainMapMin: 0.0,
            gainMapMax: 1.0,
            gamma: 1.0,
            offsetSdr: 0.015625,
            offsetHdr: 0.015625,
            auxiliaryImageType: 'urn:com:apple:photo:2020:aux:hdrgainmap',
        );

        self::assertTrue($gainMap->hasGainMap);
        self::assertSame('1.0', $gainMap->version);
        self::assertFalse($gainMap->baseRenditionIsHdr);
        self::assertSame(0.0, $gainMap->hdrCapacityMin);
        self::assertSame(3.5, $gainMap->hdrCapacityMax);
        self::assertSame(0.0, $gainMap->gainMapMin);
        self::assertSame(1.0, $gainMap->gainMapMax);
        self::assertSame(1.0, $gainMap->gamma);
        self::assertSame(0.015625, $gainMap->offsetSdr);
        self::assertSame(0.015625, $gainMap->offsetHdr);
        self::assertSame('urn:com:apple:photo:2020:aux:hdrgainmap', $gainMap->auxiliaryImageType);
    }

    /**
     * Constructs an HdrGainMap with all null properties.
     * Verifies every property defaults to null when no metadata is available.
     */
    #[Test]
    public function acceptsAllNullProperties(): void
    {
        $gainMap = new HdrGainMap(
            hasGainMap: false,
            version: null,
            baseRenditionIsHdr: null,
            hdrCapacityMin: null,
            hdrCapacityMax: null,
            gainMapMin: null,
            gainMapMax: null,
            gamma: null,
            offsetSdr: null,
            offsetHdr: null,
            auxiliaryImageType: null,
        );

        self::assertFalse($gainMap->hasGainMap);
        self::assertNull($gainMap->version);
        self::assertNull($gainMap->baseRenditionIsHdr);
        self::assertNull($gainMap->hdrCapacityMin);
        self::assertNull($gainMap->hdrCapacityMax);
        self::assertNull($gainMap->gainMapMin);
        self::assertNull($gainMap->gainMapMax);
        self::assertNull($gainMap->gamma);
        self::assertNull($gainMap->offsetSdr);
        self::assertNull($gainMap->offsetHdr);
        self::assertNull($gainMap->auxiliaryImageType);
    }
}
