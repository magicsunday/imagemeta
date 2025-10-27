<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Mpf;

/**
 * Represents an MPF payload decoded from APP2 segments.
 */
final readonly class MpfDocument
{
    /**
     * @param list<MpfEntry> $entries Ordered list of MP entries describing the constituent images.
     */
    public function __construct(
        public ?string $version,
        public int $imageCount,
        public array $entries,
        public ?MpfAttributes $attributes,
    ) {
    }
}
