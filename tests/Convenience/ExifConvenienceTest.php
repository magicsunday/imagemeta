<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Convenience;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Convenience\ExifConvenience;
use MagicSunday\ImageMeta\Value\Camera;
use MagicSunday\ImageMeta\Value\Capture;
use MagicSunday\ImageMeta\Value\Derived;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Exposure;
use MagicSunday\ImageMeta\Value\Gps;
use MagicSunday\ImageMeta\Value\GpsCoordinate;
use MagicSunday\ImageMeta\Value\Image;
use MagicSunday\ImageMeta\Value\Lens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExifConvenience.
 */
#[CoversClass(ExifConvenience::class)]
#[UsesClass(Camera::class)]
#[UsesClass(Capture::class)]
#[UsesClass(Derived::class)]
#[UsesClass(Exposure::class)]
#[UsesClass(Gps::class)]
#[UsesClass(GpsCoordinate::class)]
#[UsesClass(Image::class)]
#[UsesClass(Lens::class)]
final class ExifConvenienceTest extends TestCase
{
    #[Test]
    public function exposureSummaryFormatsValues(): void
    {
        $exposure = new Exposure(
            iso             : 200,
            exposureTimeSec : 0.5,
            fNumber         : 1.8,
            exposureBiasEv  : null,
            program         : null,
            meteringMode    : null,
            flash           : null,
            whiteBalance    : null,
            brightnessEv    : null,
            exposureMode    : null,
            gainControl     : null,
            contrast        : null,
            saturation      : null,
            sharpness       : null,
            digitalZoomRatio: null,
            shutterSpeedEv  : null,
            apertureEv      : null,
            isoLatitudeYyy  : null,
            isoLatitudeZzz  : null,
            exposureIndex   : null,
            flashEnergy     : null,
        );

        $lens = new Lens(
            null,
            null,
            null,
            50.0,
            null,
            null
        );

        $summary = ExifConvenience::exposureSummary(
            $exposure,
            $lens
        );

        self::assertSame(
            '1/2 s · f/1.8 · ISO 200 · 50 mm',
            $summary
        );
    }

    #[Test]
    public function exposureSummaryIncludes35MmEquivalent(): void
    {
        $exposure = new Exposure(
            iso             : null,
            exposureTimeSec : null,
            fNumber         : null,
            exposureBiasEv  : null,
            program         : null,
            meteringMode    : null,
            flash           : null,
            whiteBalance    : null,
            brightnessEv    : null,
            exposureMode    : null,
            gainControl     : null,
            contrast        : null,
            saturation      : null,
            sharpness       : null,
            digitalZoomRatio: null,
            shutterSpeedEv  : null,
            apertureEv      : null,
            isoLatitudeYyy  : null,
            isoLatitudeZzz  : null,
            exposureIndex   : null,
            flashEnergy     : null,
        );

        $derived = new Derived(
            ev100                   : null,
            hyperfocalDistanceMetres: null,
            circleOfConfusionMm     : null,
            fieldOfViewDiagonalDeg  : null,
            fieldOfViewHorizontalDeg: null,
            fieldOfViewVerticalDeg  : null,
            equivalent35mm          : 75,
            cropFactor              : null,
        );

        self::assertSame(
            '75 mm eq',
            ExifConvenience::exposureSummary(
                $exposure,
                null,
                $derived
            )
        );
    }

    #[Test]
    public function exposureSummaryReturnsNullWhenNoValues(): void
    {
        $exposure = new Exposure(
            iso             : null,
            exposureTimeSec : null,
            fNumber         : null,
            exposureBiasEv  : null,
            program         : null,
            meteringMode    : null,
            flash           : null,
            whiteBalance    : null,
            brightnessEv    : null,
            exposureMode    : null,
            gainControl     : null,
            contrast        : null,
            saturation      : null,
            sharpness       : null,
            digitalZoomRatio: null,
            shutterSpeedEv  : null,
            apertureEv      : null,
            isoLatitudeYyy  : null,
            isoLatitudeZzz  : null,
            exposureIndex   : null,
            flashEnergy     : null,
        );

        self::assertNull(ExifConvenience::exposureSummary($exposure));
    }

    #[Test]
    public function gpsStringFormatsCoordinates(): void
    {
        $gps = new Gps(
            latitude    : 51.5,
            longitude   : 0.125,
            latitudeRef : 'N',
            longitudeRef: 'E',
            altitude    : 45.0,
            altitudeRef : 0,
        );

        $formatted = ExifConvenience::gpsString(
            $gps,
            precision      : 3,
            includeAltitude: true
        );

        self::assertSame(
            '51.500° N, 0.125° E (45 m)',
            $formatted
        );
    }

    #[Test]
    public function gpsStringReturnsNullWithoutCoordinates(): void
    {
        $gps = new Gps();

        self::assertNull(ExifConvenience::gpsString($gps));
    }

    #[Test]
    public function imageDimensionsFormatsString(): void
    {
        $image = new Image(
            6000,
            4000,
            Orientation::TOP_LEFT,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertSame(
            '6000×4000 px',
            ExifConvenience::imageDimensions($image)
        );
    }

    #[Test]
    public function imageDimensionsReturnsNullWhenIncomplete(): void
    {
        $image = new Image(
            null,
            4000,
            Orientation::TOP_LEFT,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertNull(ExifConvenience::imageDimensions($image));
    }

    #[Test]
    public function captureDateTimeStringFormatsTimestamp(): void
    {
        $capture = new Capture(
            new DateTimeImmutable('2024-05-01T12:34:56+02:00'),
            null,
            null,
            null,
            null,
            null,
            null,
        );

        self::assertSame(
            '2024-05-01T12:34:56+02:00',
            ExifConvenience::captureDateTimeString($capture)
        );
    }

    #[Test]
    public function cameraDescriptionAvoidsDuplicateMake(): void
    {
        $camera = new Camera(
            'Canon',
            'Canon EOS R6',
            null,
            null,
            null,
            null,
        );
        $lens = new Lens(
            null,
            'RF 24-70mm',
            null,
            null,
            null,
            null
        );

        self::assertSame(
            'Canon EOS R6 · RF 24-70mm',
            ExifConvenience::cameraDescription(
                $camera,
                $lens
            )
        );
    }

    #[Test]
    public function toArrayReturnsNormalisedShape(): void
    {
        $camera = new Camera(
            'Canon',
            'EOS',
            null,
            null,
            null,
            null,
        );
        $lens = new Lens(
            null,
            'EF 50mm',
            null,
            50.0,
            null,
            null
        );
        $image = new Image(
            6000,
            4000,
            Orientation::TOP_LEFT,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
        );
        $capture = new Capture(
            new DateTimeImmutable('2024-05-01T12:34:56+02:00'),
            null,
            null,
            null,
            null,
            null,
            null,
        );
        $exposure = new Exposure(
            iso             : 200,
            exposureTimeSec : 0.5,
            fNumber         : 1.8,
            exposureBiasEv  : null,
            program         : null,
            meteringMode    : null,
            flash           : null,
            whiteBalance    : null,
            brightnessEv    : null,
            exposureMode    : null,
            gainControl     : null,
            contrast        : null,
            saturation      : null,
            sharpness       : null,
            digitalZoomRatio: null,
            shutterSpeedEv  : null,
            apertureEv      : null,
            isoLatitudeYyy  : null,
            isoLatitudeZzz  : null,
            exposureIndex   : null,
            flashEnergy     : null,
        );
        $gps = new Gps(
            51.5,
            0.125,
            'N',
            'E',
            45.0,
            0
        );

        $expected = [
            'make'        => 'Canon',
            'model'       => 'EOS',
            'lens'        => 'EF 50mm',
            'orientation' => Orientation::TOP_LEFT->value,
            'captured_at' => '2024-05-01T12:34:56+02:00',
            'exposure_s'  => 0.5,
            'fnumber'     => 1.8,
            'focal_mm'    => 50.0,
            'iso'         => 200,
            'gps_lat'     => 51.5,
            'gps_lon'     => 0.125,
            'gps_alt'     => 45.0,
        ];

        self::assertSame(
            $expected,
            ExifConvenience::toArray(
                $camera,
                $lens,
                $image,
                $capture,
                $exposure,
                $gps
            )
        );
    }
}
