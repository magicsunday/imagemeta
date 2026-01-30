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
    /**
     * Verifies that $file->mimeType equals 'image/jpeg'.
     *
     * @return void
     */
    #[Test]
    public function constructsWithBasicFileInformation(): void
    {
        $file = new File(
            mimeType: 'image/jpeg',
            fileSize: 1024,
            extension: 'jpg',
            digestSha1: null,
            digestMd5: null,
        );

        self::assertSame('image/jpeg', $file->mimeType);
        self::assertSame('jpg', $file->extension);
        self::assertSame(1024, $file->fileSize);
    }

    /**
     * Verifies that $file->mimeType is null.
     *
     * @return void
     */
    #[Test]
    public function constructsWithNullValues(): void
    {
        $file = new File(
            mimeType: null,
            fileSize: null,
            extension: null,
            digestSha1: null,
            digestMd5: null,
        );

        self::assertNull($file->mimeType);
        self::assertNull($file->extension);
        self::assertNull($file->fileSize);
    }

    /**
     * Verifies that $file->mimeType equals 'image/heic'.
     *
     * @return void
     */
    #[Test]
    public function constructsWithHeicMimeType(): void
    {
        $file = new File(
            mimeType: 'image/heic',
            fileSize: 2048,
            extension: 'heic',
            digestSha1: null,
            digestMd5: null,
        );

        self::assertSame('image/heic', $file->mimeType);
        self::assertSame('heic', $file->extension);
    }
}
