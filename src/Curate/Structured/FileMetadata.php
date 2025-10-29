<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\Container;
use MagicSunday\ImageMeta\Value\File as FileValue;
use MagicSunday\ImageMeta\Value\Integrity;

/**
 * File level metadata enriched with container and integrity details.
 */
final readonly class FileMetadata
{
    public ?string $mimeType;

    public ?int $fileSize;

    public ?string $extension;

    public ?string $digestSha1;

    public ?string $digestMd5;

    public Container $container;

    public Integrity $integrity;

    public function __construct(
        FileValue $file,
        Container $container,
        Integrity $integrity,
    ) {
        $this->mimeType   = $file->mimeType;
        $this->fileSize   = $file->fileSize;
        $this->extension  = $file->extension;
        $this->digestSha1 = $file->digestSha1;
        $this->digestMd5  = $file->digestMd5;
        $this->container  = $container;
        $this->integrity  = $integrity;
    }
}
