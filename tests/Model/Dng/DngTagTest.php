<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Dng;

use MagicSunday\ImageMeta\Model\Dng\DngTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_values;
use function count;

/**
 * Verifies DNG tag catalog completeness and ID correctness.
 */
#[CoversClass(DngTag::class)]
final class DngTagTest extends TestCase
{
    /**
     * DNG 1.7 triple-illuminant tags are present in the catalog with distinct IDs.
     */
    #[Test]
    public function dng17TripleIlluminantTagIdsAreDistinct(): void
    {
        $ids = [
            DngTag::CALIBRATION_ILLUMINANT_3,
            DngTag::CAMERA_CALIBRATION_3,
            DngTag::COLOR_MATRIX_3,
            DngTag::FORWARD_MATRIX_3,
            DngTag::ILLUMINANT_DATA_1,
            DngTag::ILLUMINANT_DATA_2,
            DngTag::ILLUMINANT_DATA_3,
            DngTag::PROFILE_HUE_SAT_MAP_DATA_3_V17,
            DngTag::REDUCTION_MATRIX_3,
        ];

        self::assertCount(count($ids), array_unique($ids));
    }

    /**
     * All public integer constants defined on DngTag have unique tag IDs.
     * This guards against accidental copy-paste collisions.
     */
    #[Test]
    public function allTagConstantsHaveUniqueValues(): void
    {
        $reflection = new ReflectionClass(DngTag::class);
        $constants  = $reflection->getConstants();

        // Exclude the deprecated alias which intentionally duplicates PROFILE_TONE_CURVE
        unset($constants['PROFILE_HUE_SAT_MAP_DATA_3']);

        $values = array_values($constants);

        self::assertCount(count($values), array_unique($values));
    }
}
