<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Exposure timing metadata for composite images.
 *
 * Mirrors the structured payload described in EXIF 3.0 §4.6.6.7.49 for
 * SourceExposureTimesOfCompositeImage, which records summary statistics and
 * per-sequence exposure durations for the source frames used to create a
 * composite image.
 */
final readonly class SourceExposureTimes
{
    /** @var list<list<float>> Exposure times grouped by capture sequence (seconds). */
    public array $sequences;

    /**
     * @param float|null        $totalExposurePeriod        Duration from the first exposure start to the last exposure end (seconds).
     * @param float|null        $usedExposureTimeSum        Sum of exposure times for the source images included in the composite (seconds).
     * @param float|null        $allExposureTimeSum         Sum of exposure times for all captured source images (seconds).
     * @param float|null        $sourceImageCount           Number of captured source images recorded as a rational value.
     * @param float|null        $maxUsedExposureTime        Longest exposure time among the used source images (seconds).
     * @param float|null        $minUsedExposureTime        Shortest exposure time among the used source images (seconds).
     * @param float|null        $longestSourceExposureTime  Exposure time of the longest overall source image (seconds).
     * @param float|null        $shortestSourceExposureTime Exposure time of the shortest overall source image (seconds).
     * @param list<list<float>> $sequences                  Exposure times grouped by capture sequence (seconds).
     */
    public function __construct(
        public ?float $totalExposurePeriod,
        public ?float $usedExposureTimeSum,
        public ?float $allExposureTimeSum,
        public ?float $sourceImageCount,
        public ?float $maxUsedExposureTime,
        public ?float $minUsedExposureTime,
        public ?float $longestSourceExposureTime,
        public ?float $shortestSourceExposureTime,
        array $sequences,
    ) {
        $this->sequences = [...$sequences];
    }
}
