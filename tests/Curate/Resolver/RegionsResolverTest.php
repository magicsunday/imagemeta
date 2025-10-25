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
use MagicSunday\ImageMeta\Value\Regions\Region;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
            '{' . self::NS_ST_AREA . '}x'             => ['0.4', '0.75'],
            '{' . self::NS_ST_AREA . '}y'             => ['0.45', '0.60'],
            '{' . self::NS_ST_AREA . '}w'             => ['0.2', '0.10'],
            '{' . self::NS_ST_AREA . '}h'             => ['0.25', '0.08'],
            '{' . self::NS_MWG . '}Type'              => ['Face', 'Focus'],
            '{' . self::NS_MWG . '}Name'              => ['Alice', ''],
            '{' . self::NS_MWG . '}Confidence'        => ['0.91', '0.5'],
            '{' . self::NS_MWG . '}Rotation'          => ['12.5', '0'],
            '{' . self::NS_APPLE . '}CenterX'         => ['0.4', '0.72'],
            '{' . self::NS_APPLE . '}CenterY'         => ['0.45', '0.61'],
            '{' . self::NS_APPLE . '}Width'           => ['0.2', '0.12'],
            '{' . self::NS_APPLE . '}Height'          => ['0.25', '0.09'],
            '{' . self::NS_APPLE . '}ConfidenceLevel' => ['0.88', '0.42'],
            '{' . self::NS_APPLE . '}AngleInfoRoll'   => ['2.0', '-5.0'],
            '{' . self::NS_APPLE . '}Name'            => ['Alice', 'Bob'],
            '{' . self::NS_APPLE . '}FaceID'          => ['101', '202'],
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
    public function enrichesMwgFacesWithAppleMetadataWithoutGeometry(): void
    {
        $document = new XmpDocument([
            '{' . self::NS_ST_AREA . '}x'             => ['0.4', '0.7'],
            '{' . self::NS_ST_AREA . '}y'             => ['0.5', '0.65'],
            '{' . self::NS_ST_AREA . '}w'             => ['0.2', '0.15'],
            '{' . self::NS_ST_AREA . '}h'             => ['0.25', '0.18'],
            '{' . self::NS_MWG . '}Type'              => ['Face', 'Face'],
            '{' . self::NS_APPLE . '}FaceID'          => ['A1', 'A2'],
            '{' . self::NS_APPLE . '}ConfidenceLevel' => ['950', '870'],
            '{' . self::NS_APPLE . '}AngleInfoRoll'   => ['1.5', '-2.0'],
        ]);

        $regions = (new RegionsResolver())->resolve($document);

        self::assertCount(2, $regions->items);

        $first = $regions->items[0];
        self::assertSame(RegionType::FACE, $first->type);
        self::assertSame('A1', $first->faceId);
        self::assertNotNull($first->confidence);
        self::assertEqualsWithDelta(0.95, $first->confidence, 0.0001);
        self::assertNotNull($first->rotationDeg);
        self::assertEqualsWithDelta(1.5, $first->rotationDeg, 0.0001);

        $second = $regions->items[1];
        self::assertSame(RegionType::FACE, $second->type);
        self::assertSame('A2', $second->faceId);
        self::assertNotNull($second->confidence);
        self::assertEqualsWithDelta(0.87, $second->confidence, 0.0001);
        self::assertNotNull($second->rotationDeg);
        self::assertEqualsWithDelta(-2.0, $second->rotationDeg, 0.0001);
    }


    #[Test]
    public function normalisesPixelCoordinatesUsingAppliedDimensions(): void
    {
        $document = new XmpDocument([
            '{' . self::NS_ST_DIM . '}w'  => ['4000'],
            '{' . self::NS_ST_DIM . '}h'  => ['3000'],
            '{' . self::NS_ST_AREA . '}x' => ['2000'],
            '{' . self::NS_ST_AREA . '}y' => ['1500'],
            '{' . self::NS_ST_AREA . '}w' => ['800'],
            '{' . self::NS_ST_AREA . '}h' => ['600'],
            '{' . self::NS_MWG . '}Type'  => ['Face'],
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAppleConfidenceProperty(): iterable
    {
        yield 'confidence-level' => ['ConfidenceLevel'];
        yield 'confidence' => ['Confidence'];
    }

    #[Test]
    #[DataProvider('provideAppleConfidenceProperty')]
    public function normalisesAppleConfidenceValues(string $confidenceProperty): void
    {
        $count          = 1001;
        $maxConfidence  = $count - 1;
        $confidenceValues = array_map(static fn (int $value): string => (string) $value, range(0, $maxConfidence));

        $values = [
            '{' . self::NS_APPLE . '}CenterX' => array_fill(0, $count, '0.5'),
            '{' . self::NS_APPLE . '}CenterY' => array_fill(0, $count, '0.5'),
            '{' . self::NS_APPLE . '}Width'   => array_fill(0, $count, '0.1'),
            '{' . self::NS_APPLE . '}Height'  => array_fill(0, $count, '0.1'),
        ];

        $values['{' . self::NS_APPLE . '}' . $confidenceProperty] = $confidenceValues;

        if ($confidenceProperty === 'ConfidenceLevel') {
            $values['{' . self::NS_APPLE . '}Confidence'] = $confidenceValues;
        }

        $resolver  = new RegionsResolver();
        $document  = new XmpDocument($values);
        $reflection = new ReflectionClass($resolver);
        $method     = $reflection->getMethod('extractAppleFaceRegions');
        $method->setAccessible(true);

        /** @var list<Region> $regions */
        $regions = $method->invoke($resolver, $document, null);

        self::assertCount($count, $regions);

        foreach ($regions as $index => $region) {
            self::assertSame(RegionType::FACE, $region->type);
            self::assertNotNull($region->confidence);

            $rawConfidence = (float) $confidenceValues[$index];
            $expectedConfidence = $rawConfidence > 1.0
                ? $rawConfidence / $maxConfidence
                : $rawConfidence;

            self::assertEqualsWithDelta($expectedConfidence, $region->confidence, 0.0001);
        }
    }

    #[Test]
    public function mergesSevenFaceRegionsAndFocusAnnotation(): void
    {
        $faceCentersX = ['0.10', '0.22', '0.34', '0.46', '0.58', '0.70', '0.82'];
        $faceCentersY = ['0.15', '0.27', '0.39', '0.51', '0.63', '0.75', '0.87'];
        $faceWidths   = ['0.12', '0.11', '0.10', '0.09', '0.08', '0.07', '0.06'];
        $faceHeights  = ['0.14', '0.13', '0.12', '0.11', '0.10', '0.09', '0.08'];
        $faceNames    = ['Alice', 'Bob', 'Charlie', 'Dora', 'Eve', 'Frank', 'Grace'];
        $faceIds      = ['1', '2', '3', '4', '5', '6', '7'];

        $mwgTypes   = array_fill(0, 7, 'Face');
        $mwgTypes[] = 'Focus';

        $document = new XmpDocument([
            '{' . self::NS_ST_AREA . '}x'        => [...$faceCentersX, '0.50'],
            '{' . self::NS_ST_AREA . '}y'        => [...$faceCentersY, '0.50'],
            '{' . self::NS_ST_AREA . '}w'        => [...$faceWidths, '0.20'],
            '{' . self::NS_ST_AREA . '}h'        => [...$faceHeights, '0.15'],
            '{' . self::NS_MWG . '}Type'         => $mwgTypes,
            '{' . self::NS_MWG . '}Name'         => [...$faceNames, ''],
            '{' . self::NS_MWG . '}Confidence'   => ['0.95', '0.90', '0.85', '0.80', '0.75', '0.70', '0.65', '0.60'],
            '{' . self::NS_APPLE . '}CenterX'    => $faceCentersX,
            '{' . self::NS_APPLE . '}CenterY'    => $faceCentersY,
            '{' . self::NS_APPLE . '}Width'      => $faceWidths,
            '{' . self::NS_APPLE . '}Height'     => $faceHeights,
            '{' . self::NS_APPLE . '}Confidence' => ['0.88', '0.86', '0.84', '0.82', '0.80', '0.78', '0.76'],
            '{' . self::NS_APPLE . '}Roll'       => ['1.0', '0.5', '-0.5', '2.0', '-1.5', '0.0', '1.5'],
            '{' . self::NS_APPLE . '}Name'       => $faceNames,
            '{' . self::NS_APPLE . '}FaceID'     => $faceIds,
        ]);

        $regions = (new RegionsResolver())->resolve($document);

        self::assertCount(8, $regions->items);

        for ($i = 0; $i < 7; ++$i) {
            $region = $regions->items[$i];
            self::assertSame(RegionType::FACE, $region->type);
            self::assertSame($faceNames[$i], $region->personName);
            self::assertSame($faceIds[$i], $region->faceId);
            self::assertNotNull($region->confidence);
            $expectedX = (float) $faceCentersX[$i] - ((float) $faceWidths[$i] / 2.0);
            $expectedY = (float) $faceCentersY[$i] - ((float) $faceHeights[$i] / 2.0);
            self::assertEqualsWithDelta($expectedX, $region->x, 0.001);
            self::assertEqualsWithDelta($expectedY, $region->y, 0.001);
            self::assertEqualsWithDelta((float) $faceWidths[$i], $region->w, 0.001);
            self::assertEqualsWithDelta((float) $faceHeights[$i], $region->h, 0.001);
        }

        $focus = $regions->items[7];
        self::assertSame(RegionType::FOCUS, $focus->type);
        self::assertNull($focus->personName);
        self::assertEqualsWithDelta(0.40, $focus->x, 0.001);
        self::assertEqualsWithDelta(0.425, $focus->y, 0.001);
        self::assertEqualsWithDelta(0.20, $focus->w, 0.001);
        self::assertEqualsWithDelta(0.15, $focus->h, 0.001);
    }

    #[Test]
    public function fallsBackToYawWhenNoAngleInfoRollOrRoll(): void
    {
        $document = new XmpDocument([
            '{' . self::NS_APPLE . '}CenterX'    => ['0.5'],
            '{' . self::NS_APPLE . '}CenterY'    => ['0.55'],
            '{' . self::NS_APPLE . '}Width'      => ['0.2'],
            '{' . self::NS_APPLE . '}Height'     => ['0.3'],
            '{' . self::NS_APPLE . '}Confidence' => ['0.77'],
            '{' . self::NS_APPLE . '}Yaw'        => ['-3.5'],
            '{' . self::NS_APPLE . '}Name'       => ['Yawy'],
            '{' . self::NS_APPLE . '}FaceUUID'   => ['abc-123'],
        ]);

        $regions = (new RegionsResolver())->resolve($document);

        self::assertCount(1, $regions->items);
        $region = $regions->items[0];

        self::assertSame(RegionType::FACE, $region->type);
        self::assertEqualsWithDelta(0.4, $region->x, 0.0001);
        self::assertEqualsWithDelta(0.4, $region->y, 0.0001);
        self::assertEqualsWithDelta(0.2, $region->w, 0.0001);
        self::assertEqualsWithDelta(0.3, $region->h, 0.0001);
        self::assertSame('Yawy', $region->personName);
        self::assertNotNull($region->confidence);
        self::assertEqualsWithDelta(0.77, $region->confidence, 0.0001);
        self::assertNotNull($region->rotationDeg);
        self::assertEqualsWithDelta(-3.5, $region->rotationDeg, 0.0001);
        self::assertSame('abc-123', $region->faceId);
    }
}
