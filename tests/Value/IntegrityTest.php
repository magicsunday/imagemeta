<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Integrity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Integrity value object for original file provenance and edits.
 * It verifies original file name and digest fields are preserved.
 * The suite covers edit flags and last-software history values.
 * This ensures integrity metadata remains accurate for audit and display.
 */
#[CoversClass(Integrity::class)]
final class IntegrityTest extends TestCase
{
    /**
     * Stores original file name metadata.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithOriginalFileName(): void
    {
        $integrity = new Integrity(
            originalFileName: 'IMG_1234.JPG',
            originalDigest: null,
            edited: null,
            historyLastSoftware: null,
        );

        self::assertSame('IMG_1234.JPG', $integrity->originalFileName);
    }

    /**
     * Stores editing history and integrity fields together.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function constructsWithEditingHistory(): void
    {
        $integrity = new Integrity(
            originalFileName: 'IMG_1234.JPG',
            originalDigest: 'sha256:abc123',
            edited: true,
            historyLastSoftware: 'Adobe Photoshop 2024',
        );

        self::assertSame('IMG_1234.JPG', $integrity->originalFileName);
        self::assertSame('sha256:abc123', $integrity->originalDigest);
        self::assertTrue($integrity->edited);
        self::assertSame('Adobe Photoshop 2024', $integrity->historyLastSoftware);
    }

    /**
     * Accepts null integrity fields.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $integrity = new Integrity(
            originalFileName: null,
            originalDigest: null,
            edited: null,
            historyLastSoftware: null,
        );

        self::assertNull($integrity->originalFileName);
        self::assertNull($integrity->originalDigest);
        self::assertNull($integrity->edited);
        self::assertNull($integrity->historyLastSoftware);
    }
}
