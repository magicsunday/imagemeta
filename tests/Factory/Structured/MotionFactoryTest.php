<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Factory\Structured\MotionFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleLivePhoto;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Motion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function round;
use function str_repeat;

/**
 * Exercises MotionFactory for mapping motion vectors from maker notes.
 * It verifies Apple acceleration vectors are converted into accelX/Y/Z values.
 * The suite checks rounding behavior and null handling when data is missing.
 * This ensures motion metadata is derived consistently from maker notes.
 *
 * @internal
 */
#[CoversClass(MotionFactory::class)]
final class MotionFactoryTest extends TestCase
{
    /**
     * Supplies an Apple maker notes acceleration vector.
     * Verifies MotionFactory maps the vector components into accelX/Y/Z.
     */
    #[Test]
    public function createsFromAppleMakerNotes(): void
    {
        $motion = $this->createMotion(
            makerNotes: $this->appleMakerNotesRecord([0.1, 0.2, 0.98]),
        );

        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertSame(0.98, $motion->accelZ);
    }

    /**
     * Provides EXIF acceleration data without any Apple maker notes vector.
     * Ensures the factory falls back to EXIF when Apple data is missing.
     */
    #[Test]
    public function fallsBackToExifDataWhenAppleVectorMissing(): void
    {
        $motion = $this->createMotion(
            exifDoc: $this->parsedExifWithAccelerationVector(-0.1, -0.2, -0.98),
        );

        self::assertEqualsWithDelta(-0.1, $motion->accelX, 1.0e-12);
        self::assertEqualsWithDelta(-0.2, $motion->accelY, 1.0e-12);
        self::assertEqualsWithDelta(-0.98, $motion->accelZ, 1.0e-12);
    }

    /**
     * Supplies both Apple and EXIF acceleration vectors.
     * Confirms the Apple maker notes vector takes precedence over EXIF data.
     */
    #[Test]
    public function prefersAppleOverExifAccelerationVector(): void
    {
        $motion = $this->createMotion(
            exifDoc: $this->parsedExifWithAccelerationVector(-0.1, -0.2, -0.98),
            makerNotes: $this->appleMakerNotesRecord([0.5, 0.6, 0.7]),
        );

        self::assertSame(0.5, $motion->accelX);
        self::assertSame(0.6, $motion->accelY);
        self::assertSame(0.7, $motion->accelZ);
    }

    /**
     * Creates Metadata without EXIF or maker notes motion data.
     * Ensures the factory returns null acceleration components.
     */
    #[Test]
    public function createsWithNullMetadata(): void
    {
        $motion = $this->createMotion();

        self::assertNull($motion->accelX);
        self::assertNull($motion->accelY);
        self::assertNull($motion->accelZ);
    }

    /**
     * Uses an Apple acceleration vector with only two components.
     * Verifies accelX and accelY are set while accelZ remains null.
     */
    #[Test]
    public function handlesPartialAccelerationVectorFromApple(): void
    {
        $motion = $this->createMotion(
            makerNotes: $this->appleMakerNotesRecord([0.1, 0.2]),
        );

        self::assertSame(0.1, $motion->accelX);
        self::assertSame(0.2, $motion->accelY);
        self::assertNull($motion->accelZ);
    }

    private function parsedExifWithAccelerationVector(float $x, float $y, float $z): ParsedExif
    {
        $scale = 1;

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

    /**
     * @param list<float>|null $accelerationVector
     */
    private function appleMakerNotesRecord(?array $accelerationVector): MakerNotesRecord
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: null,
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: new AppleLivePhoto(
                index: null,
                time: null,
                runTime: null,
                accelerationVector: $accelerationVector,
            ),
            camera: null,
            flags: [],
        );

        return new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );
    }

    private function createMotion(?ParsedExif $exifDoc = null, ?MakerNotesRecord $makerNotes = null): Motion
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $exifDoc,
            makerNotes: $makerNotes,
        );

        return new MotionFactory()->create($metadata);
    }

    private function toScaledComponent(float $value, int $scale): int
    {
        $mgal = $value / 1.0e-5;

        return (int) round($mgal * $scale);
    }
}
