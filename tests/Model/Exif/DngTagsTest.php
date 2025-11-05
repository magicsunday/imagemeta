<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test Adobe DNG tag constants.
 */
#[CoversClass(ExifTag::class)]
final class DngTagsTest extends TestCase
{
    /**
     * Tests that all core DNG tags are defined with correct hex values.
     */
    public function testCoreDngTags(): void
    {
        // Core DNG metadata tags (0xC612-0xC634)
        self::assertSame(0xC612, ExifTag::DNG_VERSION);
        self::assertSame(0xC613, ExifTag::DNG_BACKWARD_VERSION);
        self::assertSame(0xC614, ExifTag::UNIQUE_CAMERA_MODEL);
        self::assertSame(0xC615, ExifTag::LOCALIZED_CAMERA_MODEL);
        self::assertSame(0xC616, ExifTag::CFA_PLANE_COLOR);
        self::assertSame(0xC617, ExifTag::CFA_LAYOUT);
        self::assertSame(0xC618, ExifTag::LINEARIZATION_TABLE);
        self::assertSame(0xC619, ExifTag::BLACK_LEVEL_REPEAT_DIM);
        self::assertSame(0xC61A, ExifTag::BLACK_LEVEL);
        self::assertSame(0xC61B, ExifTag::BLACK_LEVEL_DELTA_H);
        self::assertSame(0xC61C, ExifTag::BLACK_LEVEL_DELTA_V);
        self::assertSame(0xC61D, ExifTag::WHITE_LEVEL);
        self::assertSame(0xC61E, ExifTag::DEFAULT_SCALE);
        self::assertSame(0xC61F, ExifTag::DEFAULT_CROP_ORIGIN);
        self::assertSame(0xC620, ExifTag::DEFAULT_CROP_SIZE);
    }

    /**
     * Tests that color and calibration DNG tags are defined.
     */
    public function testColorAndCalibrationTags(): void
    {
        // Color matrix and calibration tags (0xC621-0xC62E)
        self::assertSame(0xC621, ExifTag::COLOR_MATRIX_1);
        self::assertSame(0xC622, ExifTag::COLOR_MATRIX_2);
        self::assertSame(0xC623, ExifTag::CAMERA_CALIBRATION_1);
        self::assertSame(0xC624, ExifTag::CAMERA_CALIBRATION_2);
        self::assertSame(0xC625, ExifTag::REDUCTION_MATRIX_1);
        self::assertSame(0xC626, ExifTag::REDUCTION_MATRIX_2);
        self::assertSame(0xC627, ExifTag::ANALOG_BALANCE);
        self::assertSame(0xC628, ExifTag::AS_SHOT_NEUTRAL);
        self::assertSame(0xC629, ExifTag::AS_SHOT_WHITE_XY);
        self::assertSame(0xC62A, ExifTag::BASELINE_EXPOSURE);
        self::assertSame(0xC62B, ExifTag::BASELINE_NOISE);
        self::assertSame(0xC62C, ExifTag::BASELINE_SHARPNESS);
        self::assertSame(0xC62D, ExifTag::BAYER_GREEN_SPLIT);
        self::assertSame(0xC62E, ExifTag::LINEAR_RESPONSE_LIMIT);
    }

    /**
     * Tests that lens and imaging DNG tags are defined.
     */
    public function testLensAndImagingTags(): void
    {
        self::assertSame(0xC62F, ExifTag::CAMERA_SERIAL_NUMBER);
        self::assertSame(0xC630, ExifTag::LENS_INFO);
        self::assertSame(0xC631, ExifTag::CHROMA_BLUR_RADIUS);
        self::assertSame(0xC632, ExifTag::ANTI_ALIAS_STRENGTH);
        self::assertSame(0xC633, ExifTag::SHADOW_SCALE);
        self::assertSame(0xC634, ExifTag::DNG_PRIVATE_DATA);
        self::assertSame(0xC635, ExifTag::MAKER_NOTE_SAFETY);
    }

    /**
     * Tests that illuminant and quality DNG tags are defined.
     */
    public function testIlluminantAndQualityTags(): void
    {
        self::assertSame(0xC65A, ExifTag::CALIBRATION_ILLUMINANT_1);
        self::assertSame(0xC65B, ExifTag::CALIBRATION_ILLUMINANT_2);
        self::assertSame(0xC65C, ExifTag::BEST_QUALITY_SCALE);
        self::assertSame(0xC65D, ExifTag::RAW_DATA_UNIQUE_ID);
    }

    /**
     * Tests that original file and area DNG tags are defined.
     */
    public function testOriginalFileAndAreaTags(): void
    {
        self::assertSame(0xC68B, ExifTag::ORIGINAL_RAW_FILE_NAME);
        self::assertSame(0xC68C, ExifTag::ORIGINAL_RAW_FILE_DATA);
        self::assertSame(0xC68D, ExifTag::ACTIVE_AREA);
        self::assertSame(0xC68E, ExifTag::MASKED_AREAS);
    }

    /**
     * Tests that ICC profile DNG tags are defined.
     */
    public function testIccProfileTags(): void
    {
        self::assertSame(0xC68F, ExifTag::AS_SHOT_ICC_PROFILE);
        self::assertSame(0xC690, ExifTag::AS_SHOT_PRE_PROFILE_MATRIX);
        self::assertSame(0xC691, ExifTag::CURRENT_ICC_PROFILE);
        self::assertSame(0xC692, ExifTag::CURRENT_PRE_PROFILE_MATRIX);
        self::assertSame(0xC6BF, ExifTag::COLORIMETRIC_REFERENCE);
    }

    /**
     * Tests that calibration signature DNG tags are defined.
     */
    public function testCalibrationSignatureTags(): void
    {
        self::assertSame(0xC6F3, ExifTag::CAMERA_CALIBRATION_SIGNATURE);
        self::assertSame(0xC6F4, ExifTag::PROFILE_CALIBRATION_SIGNATURE);
    }

    /**
     * Tests that profile embed and copyright DNG tags are defined.
     */
    public function testProfileEmbedAndCopyrightTags(): void
    {
        self::assertSame(0xC6FD, ExifTag::PROFILE_EMBED_POLICY);
        self::assertSame(0xC6FE, ExifTag::PROFILE_COPYRIGHT);
    }

    /**
     * Tests that forward matrix DNG tags are defined.
     */
    public function testForwardMatrixTags(): void
    {
        self::assertSame(0xC714, ExifTag::FORWARD_MATRIX_1);
        self::assertSame(0xC715, ExifTag::FORWARD_MATRIX_2);
    }

    /**
     * Tests that preview settings DNG tags are defined.
     */
    public function testPreviewSettingsTags(): void
    {
        self::assertSame(0xC716, ExifTag::PREVIEW_APPLICATION_NAME);
        self::assertSame(0xC717, ExifTag::PREVIEW_APPLICATION_VERSION);
        self::assertSame(0xC718, ExifTag::PREVIEW_SETTINGS_NAME);
        self::assertSame(0xC719, ExifTag::PREVIEW_SETTINGS_DIGEST);
        self::assertSame(0xC71A, ExifTag::PREVIEW_COLOR_SPACE);
        self::assertSame(0xC71B, ExifTag::DNG_PREVIEW_DATE_TIME);
    }

    /**
     * Tests that digest and tile DNG tags are defined.
     */
    public function testDigestAndTileTags(): void
    {
        self::assertSame(0xC71C, ExifTag::RAW_IMAGE_DIGEST);
        self::assertSame(0xC71D, ExifTag::ORIGINAL_RAW_FILE_DIGEST);
        self::assertSame(0xC71E, ExifTag::SUB_TILE_BLOCK_SIZE);
        self::assertSame(0xC71F, ExifTag::ROW_INTERLEAVE_FACTOR);
    }

    /**
     * Tests that opcode list DNG tags are defined.
     */
    public function testOpcodeListTags(): void
    {
        self::assertSame(0xC740, ExifTag::OPCODE_LIST_1);
        self::assertSame(0xC741, ExifTag::OPCODE_LIST_2);
        self::assertSame(0xC74E, ExifTag::OPCODE_LIST_3);
    }

    /**
     * Tests that noise profile DNG tag is defined.
     */
    public function testNoiseProfileTag(): void
    {
        self::assertSame(0xC761, ExifTag::NOISE_PROFILE);
    }

    /**
     * Tests that original size DNG tags are defined.
     */
    public function testOriginalSizeTags(): void
    {
        self::assertSame(0xC791, ExifTag::ORIGINAL_DEFAULT_FINAL_SIZE);
        self::assertSame(0xC792, ExifTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE);
        self::assertSame(0xC793, ExifTag::ORIGINAL_DEFAULT_CROP_SIZE);
    }

    /**
     * Tests that encoding and rendering DNG tags are defined.
     */
    public function testEncodingAndRenderingTags(): void
    {
        self::assertSame(0xC7A3, ExifTag::PROFILE_HUE_SAT_MAP_ENCODING);
        self::assertSame(0xC7A4, ExifTag::PROFILE_LOOK_TABLE_ENCODING);
        self::assertSame(0xC7A5, ExifTag::BASELINE_EXPOSURE_OFFSET);
        self::assertSame(0xC7A6, ExifTag::DEFAULT_BLACK_RENDER);
    }

    /**
     * Tests that digest and gain DNG tags are defined.
     */
    public function testDigestAndGainTags(): void
    {
        self::assertSame(0xC7A7, ExifTag::NEW_RAW_IMAGE_DIGEST);
        self::assertSame(0xC7A8, ExifTag::RAW_TO_PREVIEW_GAIN);
    }

    /**
     * Tests that cache DNG tags are defined.
     */
    public function testCacheTags(): void
    {
        self::assertSame(0xC7A9, ExifTag::CACHE_BLOB);
        self::assertSame(0xC7AA, ExifTag::CACHE_VERSION);
    }

    /**
     * Tests that user crop DNG tag is defined.
     */
    public function testUserCropTag(): void
    {
        self::assertSame(0xC7B5, ExifTag::DEFAULT_USER_CROP);
    }
}
