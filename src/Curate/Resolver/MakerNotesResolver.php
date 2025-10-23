<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;

/**
 * Placeholder resolver for maker note specific fields. The current dataset only exposes vendor metadata,
 * therefore all lookups gracefully return null.
 */
final readonly class MakerNotesResolver
{
    public function __construct(private ?MakerNotesMetadata $metadata)
    {
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function string(string $key): ?string
    {
        return null;
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function int(string $key): ?int
    {
        return null;
    }

    /**
     * Returns null until dedicated maker notes decoders are implemented.
     */
    public function bool(string $key): ?bool
    {
        return null;
    }
}
