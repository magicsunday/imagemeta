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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function round;
use function str_repeat;

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

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            makerNotes: $makerNotes,
        );

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertSame(0.98, $motion->accelZ);
    }

    #[Test]
    public function fallsBackToExifDataWhenAppleVectorMissing(): void
    {
        $exifDoc = $this->parsedExifWithAccelerationVector(-0.1, -0.2, -0.98);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
        );

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertSame(-0.1, $motion->accelX);
        self::assertSame(-0.2, $motion->accelY);
        self::assertSame(-0.98, $motion->accelZ);
    }

    #[Test]
    public function prefersAppleOverExifAccelerationVector(): void
    {
        $exifDoc = $this->parsedExifWithAccelerationVector(-0.1, -0.2, -0.98);

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

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
            makerNotes: $makerNotes,
        );

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertSame(0.5, $motion->accelX);
        self::assertSame(0.6, $motion->accelY);
        self::assertSame(0.7, $motion->accelZ);
    }

    #[Test]
    public function createsWithNullMetadata(): void
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
        );

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertNull($motion->accelX);
        self::assertNull($motion->accelY);
        self::assertNull($motion->accelZ);
    }

    #[Test]
    public function handlesPartialAccelerationVectorFromApple(): void
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
            accelerationVector: [0.1, 0.2],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            makerNotes: $makerNotes,
        );

        $factory = new MotionFactory();
        $motion  = $factory->create($metadata);

        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertNull($motion->accelZ);
    }

    private function parsedExifWithAccelerationVector(float $x, float $y, float $z): ParsedExif
    {
        $scale = 100;

        $entry = new IfdEntry(
            ExifTag::ACCELERATION,
            10,
            3,
            [
                [$this->toScaledComponent($x, $scale), $scale],
                [$this->toScaledComponent($y, $scale), $scale],
                [$this->toScaledComponent($z, $scale), $scale],
            ],
        );

        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd([
            ExifTag::ACCELERATION => $entry,
        ]);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }

    private function toScaledComponent(float $value, int $scale): int
    {
        return (int) round($value * $scale);
    }
}
