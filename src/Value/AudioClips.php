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
 * Aggregates audio clips extracted from EXIF audio APP2 segments.
 */
final readonly class AudioClips
{
    /** @var list<AudioClip> */
    public array $clips;

    /**
     * Creates an audio clips collection value object.
     *
     * @param list<AudioClip> $clips
     */
    public function __construct(array $clips)
    {
        $this->clips = [...$clips];
    }
}
