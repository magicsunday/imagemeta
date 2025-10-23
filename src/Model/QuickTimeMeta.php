<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model;

/**
 * Holds QuickTime metadata keys that are extracted from QuickTime containers.
 */
final readonly class QuickTimeMeta
{
    /**
     * QuickTime metadata key used for the content identifier value.
     */
    public const CONTENT_IDENTIFIER_KEY = 'com.apple.quicktime.content.identifier';

    /**
     * Creates a new instance of QuickTime metadata information.
     *
     * @param array<string, string|int|float|bool> $keys Map of QuickTime metadata keys and their values.
     */
    public function __construct(public array $keys)
    {
    }

    /**
     * Returns the QuickTime content identifier value when available.
     *
     * @return string|null
     */
    public function contentIdentifier(): ?string
    {
        $key = self::CONTENT_IDENTIFIER_KEY;

        return isset($this->keys[$key]) ? (string) $this->keys[$key] : null;
    }
}
