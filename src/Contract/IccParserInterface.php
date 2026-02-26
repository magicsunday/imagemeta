<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contract;

use MagicSunday\ImageMeta\Model\Icc\IccProfile;

/**
 * Defines the contract for decoding embedded ICC profile payloads.
 */
interface IccParserInterface
{
    /**
     * Decodes ICC profile data from the full profile payload or segmented chunks.
     *
     * @param string|null        $profileData Raw ICC profile payload.
     * @param array<int, string> $segments    ICC profile chunks discovered in container metadata.
     */
    public function decode(?string $profileData, array $segments = []): ?IccProfile;
}
