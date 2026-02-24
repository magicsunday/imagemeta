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
 * Exercises the File value object for MIME type, size, and checksum metadata.
 * It verifies that extension and digest fields are preserved when supplied.
 * The suite covers null values to ensure optional fields remain nullable.
 * This keeps file identity and integrity data stable for consumers.
 */
#[CoversClass(File::class)]
final class FileTest extends TestCase
{
    /**
     * Stores basic file metadata fields.
     * It validates the transformation using representative inputs.
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
     * Accepts null file metadata values.
     * It ensures missing or invalid inputs yield no value.
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
     * Stores HEIC mime types and extensions.
     * It confirms the object preserves the supplied metadata.
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
