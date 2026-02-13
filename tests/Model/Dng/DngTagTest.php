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
     * DNG 1.7.0.0/1.7.1.0 additional tags are present with distinct IDs.
     */
    #[Test]
    public function dng17AdditionalTagIdsAreDistinct(): void
    {
        $ids = [
            DngTag::PROFILE_GAIN_TABLE_MAP_2,
            DngTag::COLUMN_INTERLEAVE_FACTOR,
            DngTag::IMAGE_SEQUENCE_INFO,
            DngTag::IMAGE_STATS,
            DngTag::PROFILE_DYNAMIC_RANGE,
            DngTag::PROFILE_GROUP_NAME,
            DngTag::JXL_DISTANCE,
            DngTag::JXL_EFFORT,
            DngTag::JXL_DECODE_SPEED,
        ];

        self::assertCount(count($ids), array_unique($ids));
    }

    /**
     * ProfileToneCurve (0xC6FC) and ProfileHueSatMapData3 v17 (0xCD39)
     * are correctly disambiguated per DNG 1.7.1.0.
     */
    #[Test]
    public function profileToneCurveAndHueSatMapData3AreDisambiguated(): void
    {
        $reflection = new ReflectionClass(DngTag::class);
        $constants  = $reflection->getConstants();

        // PROFILE_TONE_CURVE must map to 0xC6FC
        self::assertArrayHasKey('PROFILE_TONE_CURVE', $constants);

        // PROFILE_HUE_SAT_MAP_DATA_3_V17 must map to 0xCD39
        self::assertArrayHasKey('PROFILE_HUE_SAT_MAP_DATA_3_V17', $constants);

        // They must have different values
        self::assertNotSame(
            $constants['PROFILE_TONE_CURVE'],
            $constants['PROFILE_HUE_SAT_MAP_DATA_3_V17'],
        );
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
