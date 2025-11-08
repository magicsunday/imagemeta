<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\RegionsFactory;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\RegionType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegionsFactory::class)]
final class RegionsFactoryTest extends TestCase
{
    #[Test]
    public function createsEmptyRegionsWithNullXmpDoc(): void
    {
        $metadata = new Metadata();

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertInstanceOf(Regions::class, $regions);
        self::assertSame([], $regions->items);
    }

    #[Test]
    public function extractsMwgRegions(): void
    {
        $xmpDoc = $this->createMock(XmpDocument::class);
        $xmpDoc->method('get')->willReturnCallback(
            static fn (string $ns, string $name): mixed => match ($name) {
                'Type'              => ['Face'],
                'Name'              => ['John Doe'],
                'PersonDisplayName' => [],
                'Confidence'        => [0.95],
                'Rotation'          => [0.0],
                'x'                 => [0.5],
                'y'                 => [0.5],
                'w'                 => [0.2],
                'h'                 => [0.2],
                default             => null,
            },
        );

        $metadata       = new Metadata();
        $metadata->xmpDoc  = $xmpDoc;

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertInstanceOf(Regions::class, $regions);
        self::assertCount(1, $regions->items);
        self::assertSame(RegionType::FACE, $regions->items[0]->type);
        self::assertSame('John Doe', $regions->items[0]->personName);
    }

    #[Test]
    public function extractsAppleFaceRegions(): void
    {
        $xmpDoc = $this->createMock(XmpDocument::class);
        $xmpDoc->method('get')->willReturnCallback(
            static fn (string $ns, string $name): mixed => match (true) {
                str_contains($ns, 'faceinfo') && $name === 'CenterX'         => [0.5],
                str_contains($ns, 'faceinfo') && $name === 'CenterY'         => [0.5],
                str_contains($ns, 'faceinfo') && $name === 'Width'           => [0.2],
                str_contains($ns, 'faceinfo') && $name === 'Height'          => [0.2],
                str_contains($ns, 'faceinfo') && $name === 'ConfidenceLevel' => [90.0],
                str_contains($ns, 'faceinfo') && $name === 'Confidence'      => [],
                str_contains($ns, 'faceinfo') && $name === 'AngleInfoRoll'   => [],
                str_contains($ns, 'faceinfo') && $name === 'Roll'            => [],
                str_contains($ns, 'faceinfo') && $name === 'Yaw'             => [],
                str_contains($ns, 'faceinfo') && $name === 'Name'            => ['Jane Doe'],
                str_contains($ns, 'faceinfo') && $name === 'FullName'        => [],
                str_contains($ns, 'faceinfo') && $name === 'FaceID'          => ['ABC123'],
                str_contains($ns, 'faceinfo') && $name === 'FaceUUID'        => [],
                default                                                      => [],
            },
        );

        $metadata       = new Metadata();
        $metadata->xmpDoc  = $xmpDoc;

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertInstanceOf(Regions::class, $regions);
        self::assertCount(1, $regions->items);
        self::assertSame(RegionType::FACE, $regions->items[0]->type);
        self::assertSame('Jane Doe', $regions->items[0]->personName);
        self::assertSame('ABC123', $regions->items[0]->faceId);
    }

    #[Test]
    public function mergesOverlappingRegions(): void
    {
        $xmpDoc = $this->createMock(XmpDocument::class);
        $xmpDoc->method('get')->willReturnCallback(
            static fn (string $ns, string $name): mixed => match (true) {
                str_contains($ns, 'regions') && $name === 'Type'              => ['Face'],
                str_contains($ns, 'regions') && $name === 'Name'              => [''],
                str_contains($ns, 'regions') && $name === 'PersonDisplayName' => [],
                str_contains($ns, 'regions') && $name === 'Confidence'        => [],
                str_contains($ns, 'regions') && $name === 'Rotation'          => [],
                str_contains($ns, 'Area') && $name === 'x'                    => [0.5],
                str_contains($ns, 'Area') && $name === 'y'                    => [0.5],
                str_contains($ns, 'Area') && $name === 'w'                    => [0.2],
                str_contains($ns, 'Area') && $name === 'h'                    => [0.2],
                str_contains($ns, 'faceinfo') && $name === 'CenterX'          => [0.5],
                str_contains($ns, 'faceinfo') && $name === 'CenterY'          => [0.5],
                str_contains($ns, 'faceinfo') && $name === 'Width'            => [0.2],
                str_contains($ns, 'faceinfo') && $name === 'Height'           => [0.2],
                str_contains($ns, 'faceinfo') && $name === 'ConfidenceLevel'  => [],
                str_contains($ns, 'faceinfo') && $name === 'Confidence'       => [95.0],
                str_contains($ns, 'faceinfo') && $name === 'AngleInfoRoll'    => [],
                str_contains($ns, 'faceinfo') && $name === 'Roll'             => [],
                str_contains($ns, 'faceinfo') && $name === 'Yaw'              => [],
                str_contains($ns, 'faceinfo') && $name === 'Name'             => ['Bob Smith'],
                str_contains($ns, 'faceinfo') && $name === 'FullName'         => [],
                str_contains($ns, 'faceinfo') && $name === 'FaceID'           => [],
                str_contains($ns, 'faceinfo') && $name === 'FaceUUID'         => [],
                str_contains($ns, 'Dimensions') && $name === 'w'              => [],
                str_contains($ns, 'Dimensions') && $name === 'h'              => [],
                default                                                       => [],
            },
        );

        $metadata       = new Metadata();
        $metadata->xmpDoc  = $xmpDoc;

        $factory = new RegionsFactory();
        $regions = $factory->create($metadata);

        self::assertInstanceOf(Regions::class, $regions);
        self::assertCount(1, $regions->items);
        self::assertSame('Bob Smith', $regions->items[0]->personName);
        self::assertEqualsWithDelta(0.95, $regions->items[0]->confidence, 0.01);
    }
}
