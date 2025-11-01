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
     * @param string|null $mimeType   Detected mime type of the original file.
     * @param int|null    $fileSize   File size in bytes when known.
     * @param string|null $extension  File extension derived from the container.
     * @param string|null $digestSha1 Lowercase hexadecimal SHA-1 digest of the payload.
     * @param string|null $digestMd5  Lowercase hexadecimal MD5 digest of the payload.
     */
    public function __construct(
        public readonly ?string $mimeType,
        public readonly ?int $fileSize,
        public readonly ?string $extension,
        public readonly ?string $digestSha1,
        public readonly ?string $digestMd5,
    ) {
    }
}
