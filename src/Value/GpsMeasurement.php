<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\Value\Enum\GpsDifferential;
use MagicSunday\ImageMeta\Value\Enum\GpsMeasureMode;
use MagicSunday\ImageMeta\Value\Enum\GpsStatus;

/**
 * Describes GPS measurement quality data including receiver status and precision.
 */
final readonly class GpsMeasurement
{
    /**
     * @param string|null          $satellites                 Satellites used for measurement.
     * @param GpsStatus|null       $status                     Receiver status at capture time.
     * @param GpsMeasureMode|null  $measureMode                Measurement mode (2D/3D).
     * @param float|null           $dop                        Dilution of precision.
     * @param GpsDifferential|null $differential               Differential GPS indicator.
     * @param float|null           $horizontalPositioningError Horizontal positioning error in metres.
     */
    public function __construct(
        public ?string $satellites = null,
        public ?GpsStatus $status = null,
        public ?GpsMeasureMode $measureMode = null,
        public ?float $dop = null,
        public ?GpsDifferential $differential = null,
        public ?float $horizontalPositioningError = null,
    ) {
    }
}
