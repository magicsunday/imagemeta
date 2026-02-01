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
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\GpsLatLonRef;
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
 * Exercises the ExifConvenience helpers that format human-readable summaries.
 * It checks how exposure, lens, camera, and GPS values are combined into strings.
 * The tests cover null handling and default fallbacks when parts are missing.
 * This ensures the convenience layer produces stable output for UI and logs.
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
    /**
     * Formats shutter speed, aperture, ISO, and focal length into a summary string.
     * This verifies that exposure and lens values are combined in the expected order.
     *
     * @return void
     */
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

        $summary = (new ExifConvenience())->exposureSummary(
            $exposure,
            $lens
        );

        self::assertSame(
            '1/2 s · f/1.8 · ISO 200 · 50 mm',
            $summary
        );
    }

    /**
     * Uses the 35mm equivalent value from Derived when lens data is missing.
     * This ensures the summary falls back to equivalent focal length when available.
     *
     * @return void
     */
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
            (new ExifConvenience())->exposureSummary(
                $exposure,
                null,
                $derived
            )
        );
    }

    /**
     * Provides an Exposure object with no usable values.
     * This confirms the summary returns null instead of an empty or misleading string.
     *
     * @return void
     */
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

        self::assertNull((new ExifConvenience())->exposureSummary($exposure));
    }

    /**
     * Formats latitude/longitude with hemisphere letters and altitude.
     * This confirms rounding and altitude suffix formatting for GPS strings.
     *
     * @return void
     */
    #[Test]
    public function gpsStringFormatsCoordinates(): void
    {
        $gps = new Gps(
            latitude    : 51.5,
            longitude   : 0.125,
            latitudeRef : GpsLatLonRef::NORTH,
            longitudeRef: GpsLatLonRef::EAST,
            altitude    : 45.0,
            altitudeRef : GpsAltitudeRef::ABOVE_SEA_LEVEL,
        );

        $formatted = (new ExifConvenience())->gpsString(
            $gps,
            precision      : 3,
            includeAltitude: true
        );

        self::assertSame(
            '51.500° N, 0.125° E (45 m)',
            $formatted
        );
    }

    /**
     * Supplies a GPS object without coordinates.
     * This verifies gpsString returns null when required data is missing.
     *
     * @return void
     */
    #[Test]
    public function gpsStringReturnsNullWithoutCoordinates(): void
    {
        $gps = new Gps();

        self::assertNull((new ExifConvenience())->gpsString($gps));
    }

    /**
     * Formats width and height into a pixel dimension string.
     * This confirms orientation does not affect the dimensions output.
     *
     * @return void
     */
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
            (new ExifConvenience())->imageDimensions($image)
        );
    }

    /**
     * Supplies an Image with a missing width.
     * This verifies imageDimensions returns null when either dimension is absent.
     *
     * @return void
     */
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

        self::assertNull((new ExifConvenience())->imageDimensions($image));
    }

    /**
     * Formats the capture DateTime into an ISO 8601 string.
     * This verifies that the time zone offset is preserved in the output.
     *
     * @return void
     */
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
            (new ExifConvenience())->captureDateTimeString($capture)
        );
    }

    /**
     * Uses the camera model without repeating the make prefix.
     * This confirms the description avoids duplicating the brand when the model already includes it.
     *
     * @return void
     */
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
            (new ExifConvenience())->cameraDescription(
                $camera,
                $lens
            )
        );
    }

    /**
     * Aggregates camera, lens, image, capture, exposure, and GPS data into a flat array.
     * This verifies the output keys are normalized and values are formatted consistently.
     *
     * @return void
     */
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
            GpsLatLonRef::NORTH,
            GpsLatLonRef::EAST,
            45.0,
            GpsAltitudeRef::ABOVE_SEA_LEVEL,
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
            (new ExifConvenience())->toArray(
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
