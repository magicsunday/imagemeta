<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Curate\Exif\SubFactory\MotionFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Motion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MotionFactory::class)]
final class MotionFactoryTest extends TestCase
{
    #[Test]
    public function createsFromAppleMakerNotes(): void
    {
        $apple = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: [0.1, 0.2, 0.98],
        );

        $metadata             = new Metadata();
        $metadata->makerNotes = new class($apple) {
            public function __construct(public AppleMakerNotes $apple)
            {
            }
        };

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertInstanceOf(Motion::class, $motion);
        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertSame(0.98, $motion->accelZ);
    }

    #[Test]
    public function fallsBackToExifData(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('accelerationVector')->willReturn([-0.1, -0.2, -0.98]);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertInstanceOf(Motion::class, $motion);
        self::assertSame(-0.1, $motion->accelX);
        self::assertSame(-0.2, $motion->accelY);
        self::assertSame(-0.98, $motion->accelZ);
    }

    #[Test]
    public function prefersAppleOverExif(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('accelerationVector')->willReturn([-0.1, -0.2, -0.98]);

        $apple = new AppleMakerNotes(
            contentIdentifier: null,
            cameraType: null,
            hdrHeadroom: null,
            hdrGain: null,
            snr: null,
            aeStable: null,
            aeTarget: null,
            aeAverage: null,
            afStable: null,
            afPerformance: null,
            signalToNoiseRatioType: null,
            luminanceNoiseAmplitude: null,
            focusPosition: null,
            livePhotoIndex: null,
            colorTemperature: null,
            semanticStylePreset: null,
            semanticStyleWarmth: null,
            semanticStyleTone: null,
            flags: [],
            accelerationVector: [0.5, 0.6, 0.7],
        );

        $metadata             = new Metadata();
        $metadata->exifDoc    = $exifDoc;
        $metadata->makerNotes = new class($apple) {
            public function __construct(public AppleMakerNotes $apple)
            {
            }
        };

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertInstanceOf(Motion::class, $motion);
        self::assertSame(0.5, $motion->accelX);
        self::assertSame(0.6, $motion->accelY);
        self::assertSame(0.7, $motion->accelZ);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata();

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertInstanceOf(Motion::class, $motion);
        self::assertNull($motion->accelX);
        self::assertNull($motion->accelY);
        self::assertNull($motion->accelZ);
    }

    #[Test]
    public function handlesPartialAccelerationVector(): void
    {
        $exifDoc = $this->createMock(ParsedExif::class);
        $exifDoc->method('accelerationVector')->willReturn([0.1, 0.2]);

        $metadata          = new Metadata();
        $metadata->exifDoc = $exifDoc;

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertInstanceOf(Motion::class, $motion);
        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertNull($motion->accelZ);
    }
}
