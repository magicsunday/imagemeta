<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Legacy;

use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Legacy\LegacyTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for legacy EXIF tag constants.
 */
#[CoversClass(LegacyTag::class)]
final class LegacyTagTest extends TestCase
{
    /**
     * Verifies that legacy tags are defined.
     */
    #[Test]
    public function legacyTagsAreDefined(): void
    {
        self::assertSame(0x0320, LegacyTag::IMAGE_TITLE_LEGACY);
        self::assertSame(0x8827, LegacyTag::ISO_SPEED_RATINGS_LEGACY);
        self::assertSame(0xE92D, LegacyTag::PHOTOGRAPHER_LEGACY);
        self::assertSame(0xE92F, LegacyTag::CAMERA_FIRMWARE_LEGACY);
    }

    /**
     * Verifies that ISO_SPEED_RATINGS_LEGACY is an alias for PHOTOGRAPHIC_SENSITIVITY.
     */
    #[Test]
    public function isoSpeedRatingsLegacyIsAliasForPhotographicSensitivity(): void
    {
        self::assertSame(ExifTag::PHOTOGRAPHIC_SENSITIVITY, LegacyTag::ISO_SPEED_RATINGS_LEGACY);
    }

    /**
     * Verifies that DATETIME legacy constant matches EXIF tag.
     */
    #[Test]
    public function dateTimeLegacyMatchesExifTag(): void
    {
        self::assertSame(ExifTag::DATETIME, LegacyTag::DATETIME);
        self::assertSame(ExifTag::MODIFY_DATE, LegacyTag::DATETIME);
    }

    /**
     * Verifies that _VERSION_LEGACY constants have potential conflicts noted.
     */
    #[Test]
    public function versionLegacyTagsHaveDocumentedConflicts(): void
    {
        // These legacy tags share hex values with EXIF 3.0 tags (documented conflicts)
        self::assertSame(0xA436, LegacyTag::CAMERA_FIRMWARE_VERSION_LEGACY);
        self::assertSame(0xA439, LegacyTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY);
        self::assertSame(0xA43B, LegacyTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY);
        self::assertSame(0xA43C, LegacyTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY);

        // Verify they conflict with EXIF 3.0 tags
        self::assertSame(ExifTag::IMAGE_TITLE, LegacyTag::CAMERA_FIRMWARE_VERSION_LEGACY);
        self::assertSame(ExifTag::CAMERA_FIRMWARE, LegacyTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY);
    }
}
