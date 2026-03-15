<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Describes intrinsic file level metadata such as mime type and digests.
 */
final readonly class File
{
    /**
     * Creates a file metadata value object.
     *
     * @param string|null $mimeType     Detected mime type of the original file.
     * @param int|null    $fileSize     File size in bytes when known.
     * @param string|null $extension    File extension derived from the container.
     * @param string|null $digestSha256 Lowercase hexadecimal SHA-256 digest of the payload.
     */
    public function __construct(
        public ?string $mimeType,
        public ?int $fileSize,
        public ?string $extension,
        public ?string $digestSha256 = null,
    ) {
    }
}
