<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Auto focus parameters extracted from Apple maker notes.
 */
final readonly class AppleAutoFocus
{
    /**
     * @param bool|null        $stable             Indicates whether auto focus was stable during capture.
     * @param float|null       $performance        Auto focus performance metric reported by the device.
     * @param float|null       $measuredDepth      Autofocus measured depth value in meters.
     * @param float|null       $confidence         Autofocus confidence score between 0.0 and 1.0.
     * @param float|null       $focusPosition      Lens focus position in the native scale.
     * @param list<float>|null $focusDistanceRange Near and far focus distance bounds in meters.
     */
    public function __construct(
        public ?bool $stable,
        public ?float $performance,
        public ?float $measuredDepth,
        public ?float $confidence,
        public ?float $focusPosition,
        public ?array $focusDistanceRange,
    ) {
    }
}
