<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Factory\Structured\RegionCoordinateNormalizer;
use MagicSunday\ImageMeta\Factory\Structured\RegionsFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\RegionType;
use MagicSunday\ImageMeta\Value\RegionCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises RegionsFactory for extracting subject regions from XMP metadata.
 * It verifies empty outputs when no XMP document is present.
 * The suite checks region type mapping and normalized coordinates from XMP payloads.
 * This ensures region metadata is assembled consistently for structured output.
 *
 * @internal
 */
#[CoversClass(RegionsFactory::class)]
#[UsesClass(RegionCoordinateNormalizer::class)]
final class RegionsFactoryTest extends TestCase
{
    private const string NS_MWG_REGIONS = 'http://www.metadataworkinggroup.com/schemas/regions/';

    private const string NS_ST_AREA = 'http://ns.adobe.com/xmp/sType/Area#';

    private const string NS_APPLE_FACEINFO = 'http://ns.apple.com/faceinfo/1.0/';

    /**
     * Creates Metadata without an XMP document.
     * Ensures RegionsFactory returns an empty region list.
     */
    #[Test]
    public function createsEmptyRegionsWithNullXmpDoc(): void
    {
        $regions = $this->createRegions();

        self::assertSame([], $regions->items);
    }

    /**
     * Supplies MWG region tags with geometry and confidence values in XMP.
     * Verifies the factory emits a face region with the expected coordinates and metadata.
     */
    #[Test]
    public function extractsMwgRegions(): void
    {
        $xmpData = [
            '{' . self::NS_MWG_REGIONS . '}Type'              => ['Face'],
            '{' . self::NS_MWG_REGIONS . '}Name'              => ['John Doe'],
            '{' . self::NS_MWG_REGIONS . '}PersonDisplayName' => [],
            '{' . self::NS_MWG_REGIONS . '}Confidence'        => ['0.95'],
            '{' . self::NS_MWG_REGIONS . '}Rotation'          => ['0.0'],
            '{' . self::NS_ST_AREA . '}x'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}y'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}w'                     => ['0.2'],
            '{' . self::NS_ST_AREA . '}h'                     => ['0.2'],
        ];

        $regions = $this->createRegions($xmpData);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame(RegionType::Face, $region->type);
        self::assertSame('John Doe', $region->personName);
        self::assertEqualsWithDelta(0.4, $region->x, 1e-6);
        self::assertEqualsWithDelta(0.4, $region->y, 1e-6);
        self::assertEqualsWithDelta(0.2, $region->w, 1e-6);
        self::assertEqualsWithDelta(0.2, $region->h, 1e-6);
        self::assertEqualsWithDelta(0.95, $region->confidence, 1e-6);
    }

    /**
     * Supplies Apple faceinfo tags with center, size, and identity information.
     * Confirms the factory creates a face region with the expected geometry and name.
     */
    #[Test]
    public function extractsAppleFaceRegions(): void
    {
        $xmpData = [
            '{' . self::NS_APPLE_FACEINFO . '}CenterX'         => ['0.5'],
            '{' . self::NS_APPLE_FACEINFO . '}CenterY'         => ['0.5'],
            '{' . self::NS_APPLE_FACEINFO . '}Width'           => ['0.2'],
            '{' . self::NS_APPLE_FACEINFO . '}Height'          => ['0.2'],
            '{' . self::NS_APPLE_FACEINFO . '}ConfidenceLevel' => ['90.0'],
            '{' . self::NS_APPLE_FACEINFO . '}Confidence'      => [],
            '{' . self::NS_APPLE_FACEINFO . '}AngleInfoRoll'   => [],
            '{' . self::NS_APPLE_FACEINFO . '}Roll'            => [],
            '{' . self::NS_APPLE_FACEINFO . '}Yaw'             => [],
            '{' . self::NS_APPLE_FACEINFO . '}Name'            => ['Jane Doe'],
            '{' . self::NS_APPLE_FACEINFO . '}FullName'        => [],
            '{' . self::NS_APPLE_FACEINFO . '}FaceID'          => ['ABC123'],
            '{' . self::NS_APPLE_FACEINFO . '}FaceUUID'        => [],
        ];

        $regions = $this->createRegions($xmpData);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame(RegionType::Face, $region->type);
        self::assertSame('Jane Doe', $region->personName);
        self::assertSame('ABC123', $region->faceId);
        self::assertEqualsWithDelta(0.5 - 0.1, $region->x, 1e-6);
        self::assertEqualsWithDelta(0.5 - 0.1, $region->y, 1e-6);
    }

    /**
     * Supplies MWG region tags with missing geometry coordinates (no width/height).
     * Verifies the factory skips incomplete regions rather than crashing.
     */
    #[Test]
    public function skipsRegionsWithMissingGeometry(): void
    {
        $xmpData = [
            '{' . self::NS_MWG_REGIONS . '}Type'              => ['Face'],
            '{' . self::NS_MWG_REGIONS . '}Name'              => ['Missing Geometry'],
            '{' . self::NS_MWG_REGIONS . '}PersonDisplayName' => [],
            '{' . self::NS_MWG_REGIONS . '}Confidence'        => [],
            '{' . self::NS_MWG_REGIONS . '}Rotation'          => [],
            '{' . self::NS_ST_AREA . '}x'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}y'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}w'                     => [],
            '{' . self::NS_ST_AREA . '}h'                     => [],
        ];

        $regions = $this->createRegions($xmpData);

        self::assertSame([], $regions->items);
    }

    /**
     * Supplies MWG region tags with a non-standard region type label.
     * Verifies the factory handles the unknown type gracefully.
     */
    #[Test]
    public function handlesUnknownRegionTypeLabel(): void
    {
        $xmpData = [
            '{' . self::NS_MWG_REGIONS . '}Type'              => ['UnknownType'],
            '{' . self::NS_MWG_REGIONS . '}Name'              => ['Test'],
            '{' . self::NS_MWG_REGIONS . '}PersonDisplayName' => [],
            '{' . self::NS_MWG_REGIONS . '}Confidence'        => [],
            '{' . self::NS_MWG_REGIONS . '}Rotation'          => [],
            '{' . self::NS_ST_AREA . '}x'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}y'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}w'                     => ['0.2'],
            '{' . self::NS_ST_AREA . '}h'                     => ['0.2'],
        ];

        $regions = $this->createRegions($xmpData);

        // The region should still be created with an Unknown type fallback
        self::assertCount(1, $regions->items);
        self::assertSame(RegionType::Unknown, $regions->items[0]->type);
    }

    /**
     * Provides overlapping MWG and Apple faceinfo regions for the same face.
     * Ensures the factory merges them into a single region using richer metadata.
     */
    #[Test]
    public function mergesOverlappingRegions(): void
    {
        $xmpData = [
            // MWG region covering a face without person or confidence metadata.
            '{' . self::NS_MWG_REGIONS . '}Type'              => ['Face'],
            '{' . self::NS_MWG_REGIONS . '}Name'              => [''],
            '{' . self::NS_MWG_REGIONS . '}PersonDisplayName' => [],
            '{' . self::NS_MWG_REGIONS . '}Confidence'        => [],
            '{' . self::NS_MWG_REGIONS . '}Rotation'          => [],
            '{' . self::NS_ST_AREA . '}x'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}y'                     => ['0.5'],
            '{' . self::NS_ST_AREA . '}w'                     => ['0.2'],
            '{' . self::NS_ST_AREA . '}h'                     => ['0.2'],
            // Apple faceinfo entry with overlapping geometry and richer metadata.
            '{' . self::NS_APPLE_FACEINFO . '}CenterX'         => ['0.5'],
            '{' . self::NS_APPLE_FACEINFO . '}CenterY'         => ['0.5'],
            '{' . self::NS_APPLE_FACEINFO . '}Width'           => ['0.2'],
            '{' . self::NS_APPLE_FACEINFO . '}Height'          => ['0.2'],
            '{' . self::NS_APPLE_FACEINFO . '}ConfidenceLevel' => [],
            '{' . self::NS_APPLE_FACEINFO . '}Confidence'      => ['95.0'],
            '{' . self::NS_APPLE_FACEINFO . '}AngleInfoRoll'   => [],
            '{' . self::NS_APPLE_FACEINFO . '}Roll'            => [],
            '{' . self::NS_APPLE_FACEINFO . '}Yaw'             => [],
            '{' . self::NS_APPLE_FACEINFO . '}Name'            => ['Bob Smith'],
            '{' . self::NS_APPLE_FACEINFO . '}FullName'        => [],
            '{' . self::NS_APPLE_FACEINFO . '}FaceID'          => [],
            '{' . self::NS_APPLE_FACEINFO . '}FaceUUID'        => [],
        ];

        $regions = $this->createRegions($xmpData);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame('Bob Smith', $region->personName);
        self::assertEqualsWithDelta(0.95, $region->confidence, 0.01);
    }

    /**
     * @param array<string, list<string>>|null $xmpData
     */
    private function createRegions(?array $xmpData = null): RegionCollection
    {
        $xmpDoc = $xmpData !== null ? new XmpDocument($xmpData, []) : null;

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: $xmpDoc,
        );

        return new RegionsFactory()->create($metadata);
    }
}
