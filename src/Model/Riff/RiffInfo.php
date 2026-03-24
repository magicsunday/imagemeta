<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Key-value store for RIFF INFO chunk metadata.
 *
 * INFO sub-chunks use 4-character RIFF tags (INAM, IART, ICRD, etc.)
 * with null-terminated string values.
 *
 * RIFF 1991 Multimedia Programming Interface §3 — INFO List Chunk.
 */
final readonly class RiffInfo
{
    /**
     * @param array<string, string> $fields INFO tag to string value mapping.
     */
    public function __construct(
        public array $fields,
    ) {
    }

    /**
     * Returns the value for the given INFO tag, or null if absent.
     */
    public function get(string $tag): ?string
    {
        return $this->fields[$tag] ?? null;
    }
}
