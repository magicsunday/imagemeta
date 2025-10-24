<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Resolver;

use MagicSunday\ImageMeta\Curate\Resolver\RegionsResolver;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\RegionsResolver
 */
final class RegionsResolverTest extends TestCase
{
    private const string NS_MWG = 'http://www.metadataworkinggroup.com/schemas/regions/';

    private const string NS_ST_AREA = 'http://ns.adobe.com/xmp/sType/Area#';

    private const string NS_ST_DIM = 'http://ns.adobe.com/xmp/sType/Dimensions#';

    private const string NS_APPLE = 'http://ns.apple.com/faceinfo/1.0/';

    #[Test]
    public function resolvesAndMergesRegionSources(): void
    {
        $document = new XmpDocument([
            '{' . self::NS_ST_AREA . '}x'          => ['0.4', '0.75'],
            '{' . self::NS_ST_AREA . '}y'          => ['0.45', '0.60'],
            '{' . self::NS_ST_AREA . '}w'          => ['0.2', '0.10'],
            '{' . self::NS_ST_AREA . '}h'          => ['0.25', '0.08'],
            '{' . self::NS_MWG . '}Type'           => ['Face', 'Focus'],
            '{' . self::NS_MWG . '}Name'           => ['Alice', ''],
            '{' . self::NS_MWG . '}Confidence'     => ['0.91', '0.5'],
            '{' . self::NS_MWG . '}Rotation'       => ['12.5', '0'],
            '{' . self::NS_APPLE . '}CenterX'      => ['0.4', '0.72'],
            '{' . self::NS_APPLE . '}CenterY'      => ['0.45', '0.61'],
            '{' . self::NS_APPLE . '}Width'        => ['0.2', '0.12'],
            '{' . self::NS_APPLE . '}Height'       => ['0.25', '0.09'],
            '{' . self::NS_APPLE . '}Confidence'   => ['0.88', '0.42'],
            '{' . self::NS_APPLE . '}Roll'         => ['2.0', '-5.0'],
            '{' . self::NS_APPLE . '}Name'         => ['Alice', 'Bob'],
            '{' . self::NS_APPLE . '}FaceID'       => ['101', '202'],
        ]);

        $resolver = new RegionsResolver();
        $regions  = $resolver->resolve($document);

        self::assertCount(3, $regions->items);

        $faceRegion = $regions->items[0];
        self::assertSame(RegionType::FACE, $faceRegion->type);
        self::assertEqualsWithDelta(0.3, $faceRegion->x, 0.0001);
        self::assertEqualsWithDelta(0.325, $faceRegion->y, 0.0001);
        self::assertEqualsWithDelta(0.2, $faceRegion->w, 0.0001);
        self::assertEqualsWithDelta(0.25, $faceRegion->h, 0.0001);
        self::assertSame('Alice', $faceRegion->personName);
        self::assertNotNull($faceRegion->confidence);
        self::assertEqualsWithDelta(0.91, $faceRegion->confidence, 0.0001);
        self::assertNotNull($faceRegion->rotationDeg);
        self::assertEqualsWithDelta(12.5, $faceRegion->rotationDeg, 0.0001);
        self::assertSame('101', $faceRegion->faceId);

        $focusRegion = $regions->items[1];
        self::assertSame(RegionType::FOCUS, $focusRegion->type);
        self::assertEqualsWithDelta(0.7, $focusRegion->x, 0.0001);
        self::assertEqualsWithDelta(0.56, $focusRegion->y, 0.0001);
        self::assertEqualsWithDelta(0.1, $focusRegion->w, 0.0001);
        self::assertEqualsWithDelta(0.08, $focusRegion->h, 0.0001);
        self::assertNull($focusRegion->personName);
        self::assertNotNull($focusRegion->confidence);
        self::assertEqualsWithDelta(0.5, $focusRegion->confidence, 0.0001);
        self::assertNotNull($focusRegion->rotationDeg);
        self::assertEqualsWithDelta(0.0, $focusRegion->rotationDeg, 0.0001);
        self::assertNull($focusRegion->faceId);

        $appleOnlyRegion = $regions->items[2];
        self::assertSame(RegionType::FACE, $appleOnlyRegion->type);
        self::assertEqualsWithDelta(0.66, $appleOnlyRegion->x, 0.0001);
        self::assertEqualsWithDelta(0.565, $appleOnlyRegion->y, 0.0001);
        self::assertEqualsWithDelta(0.12, $appleOnlyRegion->w, 0.0001);
        self::assertEqualsWithDelta(0.09, $appleOnlyRegion->h, 0.0001);
        self::assertSame('Bob', $appleOnlyRegion->personName);
        self::assertNotNull($appleOnlyRegion->confidence);
        self::assertEqualsWithDelta(0.42, $appleOnlyRegion->confidence, 0.0001);
        self::assertNotNull($appleOnlyRegion->rotationDeg);
        self::assertEqualsWithDelta(-5.0, $appleOnlyRegion->rotationDeg, 0.0001);
        self::assertSame('202', $appleOnlyRegion->faceId);
    }

    #[Test]
    public function normalisesPixelCoordinatesUsingAppliedDimensions(): void
    {
        $document = new XmpDocument([
            '{' . self::NS_ST_DIM . '}w' => ['4000'],
            '{' . self::NS_ST_DIM . '}h' => ['3000'],
            '{' . self::NS_ST_AREA . '}x' => ['2000'],
            '{' . self::NS_ST_AREA . '}y' => ['1500'],
            '{' . self::NS_ST_AREA . '}w' => ['800'],
            '{' . self::NS_ST_AREA . '}h' => ['600'],
            '{' . self::NS_MWG . '}Type' => ['Face'],
        ]);

        $resolver = new RegionsResolver();
        $regions  = $resolver->resolve($document);

        self::assertCount(1, $regions->items);
        $region = $regions->items[0];

        self::assertSame(RegionType::FACE, $region->type);
        self::assertEqualsWithDelta(0.4, $region->x, 0.0001);
        self::assertEqualsWithDelta(0.4, $region->y, 0.0001);
        self::assertEqualsWithDelta(0.2, $region->w, 0.0001);
        self::assertEqualsWithDelta(0.2, $region->h, 0.0001);
    }
}
