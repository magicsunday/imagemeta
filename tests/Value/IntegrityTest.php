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
 * Tests for the Integrity value object.
 */
#[CoversClass(Integrity::class)]
final class IntegrityTest extends TestCase
{
    #[Test]
    public function constructsWithOriginalFileName(): void
    {
        $integrity = new Integrity(
            originalFileName: 'IMG_1234.JPG',
            originalDigest: null,
            edited: null,
            historyLastSoftware: null,
            imageHistory: null,
            makerNotesSafe: null,
        );

        self::assertSame('IMG_1234.JPG', $integrity->originalFileName);
    }

    #[Test]
    public function constructsWithEditingHistory(): void
    {
        $integrity = new Integrity(
            originalFileName: 'IMG_1234.JPG',
            originalDigest: 'sha256:abc123',
            edited: true,
            historyLastSoftware: 'Adobe Photoshop 2024',
            imageHistory: 'Adjusted exposure, cropped',
            makerNotesSafe: false,
        );

        self::assertSame('IMG_1234.JPG', $integrity->originalFileName);
        self::assertSame('sha256:abc123', $integrity->originalDigest);
        self::assertTrue($integrity->edited);
        self::assertSame('Adobe Photoshop 2024', $integrity->historyLastSoftware);
        self::assertSame('Adjusted exposure, cropped', $integrity->imageHistory);
        self::assertFalse($integrity->makerNotesSafe);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $integrity = new Integrity(
            originalFileName: null,
            originalDigest: null,
            edited: null,
            historyLastSoftware: null,
            imageHistory: null,
            makerNotesSafe: null,
        );

        self::assertNull($integrity->originalFileName);
        self::assertNull($integrity->originalDigest);
        self::assertNull($integrity->edited);
        self::assertNull($integrity->historyLastSoftware);
        self::assertNull($integrity->imageHistory);
        self::assertNull($integrity->makerNotesSafe);
    }
}
