<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

use MagicSunday\ImageMeta\Value\RunTime;

/**
 * Live Photo and motion metadata extracted from Apple maker notes.
 */
final readonly class AppleLivePhoto
{
    /**
     * @param int|null         $index              Index of the representative frame in a Live Photo sequence.
     * @param float|null       $time               Normalised Live Photo timestamp in seconds.
     * @param RunTime|null     $runTime            Capture runtime metadata describing the CMTime payload.
     * @param list<float>|null $accelerationVector Acceleration vector recorded during capture.
     */
    public function __construct(
        public ?int $index,
        public ?float $time,
        public ?RunTime $runTime,
        public ?array $accelerationVector,
    ) {
    }
}
