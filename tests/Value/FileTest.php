<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the File value object.
 */
#[CoversClass(File::class)]
final class FileTest extends TestCase
{
    #[Test]
    public function constructsWithBasicFileInformation(): void
    {
        $file = new File(
            mimeType: 'image/jpeg',
            extension: 'jpg',
            size: 1024,
        );

        self::assertSame('image/jpeg', $file->mimeType);
        self::assertSame('jpg', $file->extension);
        self::assertSame(1024, $file->size);
    }

    #[Test]
    public function constructsWithNullValues(): void
    {
        $file = new File(
            mimeType: null,
            extension: null,
            size: null,
        );

        self::assertNull($file->mimeType);
        self::assertNull($file->extension);
        self::assertNull($file->size);
    }

    #[Test]
    public function constructsWithHeicMimeType(): void
    {
        $file = new File(
            mimeType: 'image/heic',
            extension: 'heic',
            size: 2048,
        );

        self::assertSame('image/heic', $file->mimeType);
        self::assertSame('heic', $file->extension);
    }
}
