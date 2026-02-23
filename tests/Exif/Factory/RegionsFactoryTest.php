<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\RegionCoordinateNormaliser;
use MagicSunday\ImageMeta\Exif\Factory\RegionsFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\RegionType;
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
#[UsesClass(RegionCoordinateNormaliser::class)]
final class RegionsFactoryTest extends TestCase
{
    /**
     * Creates Metadata without an XMP document.
     * Ensures RegionsFactory returns an empty region list.
     *
     * @return void
     */
    #[Test]
    public function createsEmptyRegionsWithNullXmpDoc(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertSame([], $regions->items);
    }

    /**
     * Supplies MWG region tags with geometry and confidence values in XMP.
     * Verifies the factory emits a face region with the expected coordinates and metadata.
     *
     * @return void
     */
    #[Test]
    public function extractsMwgRegions(): void
    {
        $nsMwgRegions = 'http://www.metadataworkinggroup.com/schemas/regions/';
        $nsStArea     = 'http://ns.adobe.com/xmp/sType/Area#';

        $xmpData = [
            '{' . $nsMwgRegions . '}Type'              => ['Face'],
            '{' . $nsMwgRegions . '}Name'              => ['John Doe'],
            '{' . $nsMwgRegions . '}PersonDisplayName' => [],
            '{' . $nsMwgRegions . '}Confidence'        => ['0.95'],
            '{' . $nsMwgRegions . '}Rotation'          => ['0.0'],
            '{' . $nsStArea . '}x'                     => ['0.5'],
            '{' . $nsStArea . '}y'                     => ['0.5'],
            '{' . $nsStArea . '}w'                     => ['0.2'],
            '{' . $nsStArea . '}h'                     => ['0.2'],
        ];

        $xmpDoc = new XmpDocument($xmpData, []);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: $xmpDoc,
        );

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame(RegionType::FACE, $region->type);
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
     *
     * @return void
     */
    #[Test]
    public function extractsAppleFaceRegions(): void
    {
        $nsAppleFaceInfo = 'http://ns.apple.com/faceinfo/1.0/';

        $xmpData = [
            '{' . $nsAppleFaceInfo . '}CenterX'         => ['0.5'],
            '{' . $nsAppleFaceInfo . '}CenterY'         => ['0.5'],
            '{' . $nsAppleFaceInfo . '}Width'           => ['0.2'],
            '{' . $nsAppleFaceInfo . '}Height'          => ['0.2'],
            '{' . $nsAppleFaceInfo . '}ConfidenceLevel' => ['90.0'],
            '{' . $nsAppleFaceInfo . '}Confidence'      => [],
            '{' . $nsAppleFaceInfo . '}AngleInfoRoll'   => [],
            '{' . $nsAppleFaceInfo . '}Roll'            => [],
            '{' . $nsAppleFaceInfo . '}Yaw'             => [],
            '{' . $nsAppleFaceInfo . '}Name'            => ['Jane Doe'],
            '{' . $nsAppleFaceInfo . '}FullName'        => [],
            '{' . $nsAppleFaceInfo . '}FaceID'          => ['ABC123'],
            '{' . $nsAppleFaceInfo . '}FaceUUID'        => [],
        ];

        $xmpDoc = new XmpDocument($xmpData, []);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: $xmpDoc,
        );

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame(RegionType::FACE, $region->type);
        self::assertSame('Jane Doe', $region->personName);
        self::assertSame('ABC123', $region->faceId);
        self::assertEqualsWithDelta(0.5 - 0.1, $region->x, 1e-6);
        self::assertEqualsWithDelta(0.5 - 0.1, $region->y, 1e-6);
    }

    /**
     * Provides overlapping MWG and Apple faceinfo regions for the same face.
     * Ensures the factory merges them into a single region using richer metadata.
     *
     * @return void
     */
    #[Test]
    public function mergesOverlappingRegions(): void
    {
        $nsMwgRegions   = 'http://www.metadataworkinggroup.com/schemas/regions/';
        $nsStArea       = 'http://ns.adobe.com/xmp/sType/Area#';
        $nsAppleFaceInf = 'http://ns.apple.com/faceinfo/1.0/';

        $xmpData = [
            // MWG region covering a face without person or confidence metadata.
            '{' . $nsMwgRegions . '}Type'              => ['Face'],
            '{' . $nsMwgRegions . '}Name'              => [''],
            '{' . $nsMwgRegions . '}PersonDisplayName' => [],
            '{' . $nsMwgRegions . '}Confidence'        => [],
            '{' . $nsMwgRegions . '}Rotation'          => [],
            '{' . $nsStArea . '}x'                     => ['0.5'],
            '{' . $nsStArea . '}y'                     => ['0.5'],
            '{' . $nsStArea . '}w'                     => ['0.2'],
            '{' . $nsStArea . '}h'                     => ['0.2'],
            // Apple faceinfo entry with overlapping geometry and richer metadata.
            '{' . $nsAppleFaceInf . '}CenterX'         => ['0.5'],
            '{' . $nsAppleFaceInf . '}CenterY'         => ['0.5'],
            '{' . $nsAppleFaceInf . '}Width'           => ['0.2'],
            '{' . $nsAppleFaceInf . '}Height'          => ['0.2'],
            '{' . $nsAppleFaceInf . '}ConfidenceLevel' => [],
            '{' . $nsAppleFaceInf . '}Confidence'      => ['95.0'],
            '{' . $nsAppleFaceInf . '}AngleInfoRoll'   => [],
            '{' . $nsAppleFaceInf . '}Roll'            => [],
            '{' . $nsAppleFaceInf . '}Yaw'             => [],
            '{' . $nsAppleFaceInf . '}Name'            => ['Bob Smith'],
            '{' . $nsAppleFaceInf . '}FullName'        => [],
            '{' . $nsAppleFaceInf . '}FaceID'          => [],
            '{' . $nsAppleFaceInf . '}FaceUUID'        => [],
        ];

        $xmpDoc = new XmpDocument($xmpData, []);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: null,
            xmpBlobs: [],
            xmpDoc: $xmpDoc,
        );

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertCount(1, $regions->items);

        $region = $regions->items[0];

        self::assertSame('Bob Smith', $region->personName);
        self::assertEqualsWithDelta(0.95, $region->confidence, 0.01);
    }
}
