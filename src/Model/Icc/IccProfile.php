<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Icc;

/**
 * Immutable ICC profile metadata extracted by the ICC parser.
 *
 * @phpstan-type XyzTriplet array{x: float, y: float, z: float}
 * @phpstan-type TrcCurve array{gamma: float}|array{table: list<int>}
 * @phpstan-type ViewingConditions array{
 *   illuminant: XyzTriplet,
 *   surround: XyzTriplet,
 *   illuminantType: int
 * }
 * @phpstan-type Measurement array{
 *   observer: int,
 *   backing: XyzTriplet,
 *   geometry: int,
 *   flare: float,
 *   illuminant: int
 * }
 */
final readonly class IccProfile
{
    /**
     * @param XyzTriplet|null       $whitePoint
     * @param XyzTriplet|null       $blackPoint
     * @param XyzTriplet|null       $redMatrixColumn
     * @param XyzTriplet|null       $greenMatrixColumn
     * @param XyzTriplet|null       $blueMatrixColumn
     * @param XyzTriplet|null       $luminance
     * @param TrcCurve|null         $redTRC
     * @param TrcCurve|null         $greenTRC
     * @param TrcCurve|null         $blueTRC
     * @param ViewingConditions|null $viewingConditions
     * @param Measurement|null      $measurement
     * @param XyzTriplet|null       $illuminant
     */
    public function __construct(
        public ?string $description,
        public ?string $copyright,
        public ?array $whitePoint,
        public ?array $blackPoint,
        public ?array $redMatrixColumn,
        public ?array $greenMatrixColumn,
        public ?array $blueMatrixColumn,
        public ?array $luminance,
        public ?array $redTRC,
        public ?array $greenTRC,
        public ?array $blueTRC,
        public ?string $deviceMfgDesc,
        public ?string $deviceModelDesc,
        public ?string $technology,
        public ?array $viewingConditions,
        public ?array $measurement,
        public ?string $version,
        public ?string $pcs,
        public ?string $renderingIntent,
        public ?string $profileId,
        public ?string $cmmType,
        public ?string $profileClass,
        public ?string $colorSpace,
        public ?string $profileDateTime,
        public ?string $profileDateTimeUtc,
        public ?string $profileSignature,
        public ?string $profileFlags,
        public ?string $primaryPlatform,
        public ?string $deviceManufacturer,
        public ?string $deviceModel,
        public ?string $deviceAttributes,
        public ?string $profileCreator,
        public ?array $illuminant,
    ) {
    }
}
