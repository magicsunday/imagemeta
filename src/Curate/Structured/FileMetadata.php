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
    public function __construct(
        public FileValue $file,
        public Container $container,
        public Integrity $integrity,
    ) {
    }

    public function file(): FileValue
    {
        return $this->file;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function integrity(): Integrity
    {
        return $this->integrity;
    }
}
