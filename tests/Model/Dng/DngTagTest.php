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
use PHPUnit\Framework\TestCase;

/**
 * Test Adobe DNG tag constants.
 */
#[CoversClass(DngTag::class)]
final class DngTagTest extends TestCase
{
    /**
     * Tests that all core DNG tags are defined with correct hex values.
     */
    public function testCoreDngTags(): void
    {
        // Core DNG metadata tags (0xC612-0xC634)
        self::assertSame(0xC612, DngTag::DNG_VERSION);
        self::assertSame(0xC613, DngTag::DNG_BACKWARD_VERSION);
        self::assertSame(0xC614, DngTag::UNIQUE_CAMERA_MODEL);
        self::assertSame(0xC615, DngTag::LOCALIZED_CAMERA_MODEL);
        self::assertSame(0xC616, DngTag::CFA_PLANE_COLOR);
        self::assertSame(0xC617, DngTag::CFA_LAYOUT);
        self::assertSame(0xC618, DngTag::LINEARIZATION_TABLE);
        self::assertSame(0xC619, DngTag::BLACK_LEVEL_REPEAT_DIM);
        self::assertSame(0xC61A, DngTag::BLACK_LEVEL);
        self::assertSame(0xC61B, DngTag::BLACK_LEVEL_DELTA_H);
        self::assertSame(0xC61C, DngTag::BLACK_LEVEL_DELTA_V);
        self::assertSame(0xC61D, DngTag::WHITE_LEVEL);
        self::assertSame(0xC61E, DngTag::DEFAULT_SCALE);
        self::assertSame(0xC61F, DngTag::DEFAULT_CROP_ORIGIN);
        self::assertSame(0xC620, DngTag::DEFAULT_CROP_SIZE);
    }

    /**
     * Tests that color and calibration DNG tags are defined.
     */
    public function testColorAndCalibrationTags(): void
    {
        // Color matrix and calibration tags (0xC621-0xC62E)
        self::assertSame(0xC621, DngTag::COLOR_MATRIX_1);
        self::assertSame(0xC622, DngTag::COLOR_MATRIX_2);
        self::assertSame(0xC623, DngTag::CAMERA_CALIBRATION_1);
        self::assertSame(0xC624, DngTag::CAMERA_CALIBRATION_2);
        self::assertSame(0xC625, DngTag::REDUCTION_MATRIX_1);
        self::assertSame(0xC626, DngTag::REDUCTION_MATRIX_2);
        self::assertSame(0xC627, DngTag::ANALOG_BALANCE);
        self::assertSame(0xC628, DngTag::AS_SHOT_NEUTRAL);
        self::assertSame(0xC629, DngTag::AS_SHOT_WHITE_XY);
        self::assertSame(0xC62A, DngTag::BASELINE_EXPOSURE);
        self::assertSame(0xC62B, DngTag::BASELINE_NOISE);
        self::assertSame(0xC62C, DngTag::BASELINE_SHARPNESS);
        self::assertSame(0xC62D, DngTag::BAYER_GREEN_SPLIT);
        self::assertSame(0xC62E, DngTag::LINEAR_RESPONSE_LIMIT);
    }

    /**
     * Tests that lens and imaging DNG tags are defined.
     */
    public function testLensAndImagingTags(): void
    {
        self::assertSame(0xC62F, DngTag::CAMERA_SERIAL_NUMBER);
        self::assertSame(0xC630, DngTag::LENS_INFO);
        self::assertSame(0xC631, DngTag::CHROMA_BLUR_RADIUS);
        self::assertSame(0xC632, DngTag::ANTI_ALIAS_STRENGTH);
        self::assertSame(0xC633, DngTag::SHADOW_SCALE);
        self::assertSame(0xC634, DngTag::DNG_PRIVATE_DATA);
        self::assertSame(0xC635, DngTag::MAKER_NOTE_SAFETY);
    }

    /**
     * Tests that illuminant and quality DNG tags are defined.
     */
    public function testIlluminantAndQualityTags(): void
    {
        self::assertSame(0xC65A, DngTag::CALIBRATION_ILLUMINANT_1);
        self::assertSame(0xC65B, DngTag::CALIBRATION_ILLUMINANT_2);
        self::assertSame(0xC65C, DngTag::BEST_QUALITY_SCALE);
        self::assertSame(0xC65D, DngTag::RAW_DATA_UNIQUE_ID);
    }

    /**
     * Tests that original file and area DNG tags are defined.
     */
    public function testOriginalFileAndAreaTags(): void
    {
        self::assertSame(0xC68B, DngTag::ORIGINAL_RAW_FILE_NAME);
        self::assertSame(0xC68C, DngTag::ORIGINAL_RAW_FILE_DATA);
        self::assertSame(0xC68D, DngTag::ACTIVE_AREA);
        self::assertSame(0xC68E, DngTag::MASKED_AREAS);
    }

    /**
     * Tests that ICC profile DNG tags are defined.
     */
    public function testIccProfileTags(): void
    {
        self::assertSame(0xC68F, DngTag::AS_SHOT_ICC_PROFILE);
        self::assertSame(0xC690, DngTag::AS_SHOT_PRE_PROFILE_MATRIX);
        self::assertSame(0xC691, DngTag::CURRENT_ICC_PROFILE);
        self::assertSame(0xC692, DngTag::CURRENT_PRE_PROFILE_MATRIX);
        self::assertSame(0xC6BF, DngTag::COLORIMETRIC_REFERENCE);
    }

    /**
     * Tests that calibration signature DNG tags are defined.
     */
    public function testCalibrationSignatureTags(): void
    {
        self::assertSame(0xC6F3, DngTag::CAMERA_CALIBRATION_SIGNATURE);
        self::assertSame(0xC6F4, DngTag::PROFILE_CALIBRATION_SIGNATURE);
        self::assertSame(0xC6F5, DngTag::EXTRA_CAMERA_PROFILES);
        self::assertSame(0xC6F6, DngTag::AS_SHOT_PROFILE_NAME);
        self::assertSame(0xC6F7, DngTag::NOISE_REDUCTION_APPLIED);
        self::assertSame(0xC6F8, DngTag::PROFILE_NAME);
    }

    /**
     * Tests that profile hue/sat map DNG tags are defined.
     */
    public function testProfileHueSatMapTags(): void
    {
        self::assertSame(0xC6F9, DngTag::PROFILE_HUE_SAT_MAP_DIMS);
        self::assertSame(0xC6FA, DngTag::PROFILE_HUE_SAT_MAP_DATA_1);
        self::assertSame(0xC6FB, DngTag::PROFILE_HUE_SAT_MAP_DATA_2);
        self::assertSame(0xC6FC, DngTag::PROFILE_HUE_SAT_MAP_DATA_3);
    }

    /**
     * Tests that profile embed and copyright DNG tags are defined.
     */
    public function testProfileEmbedAndCopyrightTags(): void
    {
        self::assertSame(0xC6FD, DngTag::PROFILE_EMBED_POLICY);
        self::assertSame(0xC6FE, DngTag::PROFILE_COPYRIGHT);
    }

    /**
     * Tests that forward matrix DNG tags are defined.
     */
    public function testForwardMatrixTags(): void
    {
        self::assertSame(0xC714, DngTag::FORWARD_MATRIX_1);
        self::assertSame(0xC715, DngTag::FORWARD_MATRIX_2);
    }

    /**
     * Tests that preview settings DNG tags are defined.
     */
    public function testPreviewSettingsTags(): void
    {
        self::assertSame(0xC716, DngTag::PREVIEW_APPLICATION_NAME);
        self::assertSame(0xC717, DngTag::PREVIEW_APPLICATION_VERSION);
        self::assertSame(0xC718, DngTag::PREVIEW_SETTINGS_NAME);
        self::assertSame(0xC719, DngTag::PREVIEW_SETTINGS_DIGEST);
        self::assertSame(0xC71A, DngTag::PREVIEW_COLOR_SPACE);
        self::assertSame(0xC71B, DngTag::PREVIEW_DATE_TIME);
    }

    /**
     * Tests that digest and tile DNG tags are defined.
     */
    public function testDigestAndTileTags(): void
    {
        self::assertSame(0xC71C, DngTag::RAW_IMAGE_DIGEST);
        self::assertSame(0xC71D, DngTag::ORIGINAL_RAW_FILE_DIGEST);
        self::assertSame(0xC71E, DngTag::SUB_TILE_BLOCK_SIZE);
        self::assertSame(0xC71F, DngTag::ROW_INTERLEAVE_FACTOR);
    }

    /**
     * Tests that profile look table DNG tags are defined.
     */
    public function testProfileLookTableTags(): void
    {
        self::assertSame(0xC725, DngTag::PROFILE_LOOK_TABLE_DIMS);
        self::assertSame(0xC726, DngTag::PROFILE_LOOK_TABLE_DATA);
    }

    /**
     * Tests that opcode list DNG tags are defined.
     */
    public function testOpcodeListTags(): void
    {
        self::assertSame(0xC740, DngTag::OPCODE_LIST_1);
        self::assertSame(0xC741, DngTag::OPCODE_LIST_2);
        self::assertSame(0xC74E, DngTag::OPCODE_LIST_3);
    }

    /**
     * Tests that noise profile DNG tag is defined.
     */
    public function testNoiseProfileTag(): void
    {
        self::assertSame(0xC761, DngTag::NOISE_PROFILE);
    }

    /**
     * Tests that original size DNG tags are defined.
     */
    public function testOriginalSizeTags(): void
    {
        self::assertSame(0xC791, DngTag::ORIGINAL_DEFAULT_FINAL_SIZE);
        self::assertSame(0xC792, DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE);
        self::assertSame(0xC793, DngTag::ORIGINAL_DEFAULT_CROP_SIZE);
    }

    /**
     * Tests that encoding and rendering DNG tags are defined.
     */
    public function testEncodingAndRenderingTags(): void
    {
        self::assertSame(0xC7A3, DngTag::PROFILE_HUE_SAT_MAP_ENCODING);
        self::assertSame(0xC7A4, DngTag::PROFILE_LOOK_TABLE_ENCODING);
        self::assertSame(0xC7A5, DngTag::BASELINE_EXPOSURE_OFFSET);
        self::assertSame(0xC7A6, DngTag::DEFAULT_BLACK_RENDER);
    }

    /**
     * Tests that digest and gain DNG tags are defined.
     */
    public function testDigestAndGainTags(): void
    {
        self::assertSame(0xC7A7, DngTag::NEW_RAW_IMAGE_DIGEST);
        self::assertSame(0xC7A8, DngTag::RAW_TO_PREVIEW_GAIN);
    }

    /**
     * Tests that cache DNG tags are defined.
     */
    public function testCacheTags(): void
    {
        self::assertSame(0xC7A9, DngTag::CACHE_BLOB);
        self::assertSame(0xC7AA, DngTag::CACHE_VERSION);
    }

    /**
     * Tests that user crop DNG tag is defined.
     */
    public function testUserCropTag(): void
    {
        self::assertSame(0xC7B5, DngTag::DEFAULT_USER_CROP);
    }
}
