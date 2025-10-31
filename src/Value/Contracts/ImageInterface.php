<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Contracts;

use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\Orientation;

interface ImageInterface
{
    public function width(): ?int;

    public function height(): ?int;

    public function orientation(): ?Orientation;

    public function bitsPerSample(): ?int;

    public function colorSpace(): ?ColorSpace;

    public function imageUniqueId(): ?string;

    public function imageNumber(): ?int;

    public function documentName(): ?string;

    public function description(): ?string;

    public function title(): ?string;

    /**
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array;

    public function compressedBitsPerPixel(): ?float;

    public function interlace(): ?int;

    public function userComment(): ?string;

    public function userCommentEncoding(): ?string;
}
