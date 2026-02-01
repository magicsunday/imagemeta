<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Standards;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Standards value object for EXIF and related version strings.
 * It verifies EXIF version, profile, and FlashPix version fields are preserved.
 * The suite covers null handling for optional standard identifiers.
 * This ensures standards metadata remains stable for reporting and compatibility checks.
 */
#[CoversClass(Standards::class)]
final class StandardsTest extends TestCase
{
    /**
     * Stores EXIF version identifiers.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithExifVersion(): void
    {
        $standards = new Standards(
            exifVersion: '3.00',
            profile: null,
            flashpixVersion: null,
        );

        self::assertSame('3.00', $standards->exifVersion);
    }

    /**
     * Stores EXIF profile and FlashPix version identifiers.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function constructsWithAllFields(): void
    {
        $standards = new Standards(
            exifVersion: '2.32',
            profile: 'Baseline',
            flashpixVersion: '1.00',
        );

        self::assertSame('2.32', $standards->exifVersion);
        self::assertSame('Baseline', $standards->profile);
        self::assertSame('1.00', $standards->flashpixVersion);
    }

    /**
     * Accepts null standards metadata values.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $standards = new Standards(
            exifVersion: null,
            profile: null,
            flashpixVersion: null,
        );

        self::assertNull($standards->exifVersion);
        self::assertNull($standards->profile);
        self::assertNull($standards->flashpixVersion);
    }
}
