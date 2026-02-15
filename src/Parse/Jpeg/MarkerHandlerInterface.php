<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\Stream;

/**
 * Strategy contract for handling metadata-bearing JPEG marker payloads.
 */
interface MarkerHandlerInterface
{
    /**
     * Indicates whether this handler participates for the given JPEG marker.
     */
    public function canHandle(int $marker): bool;

    /**
     * Processes one marker payload.
     *
     * Implementations may no-op when the payload signature is not relevant.
     *
     * @param Stream $stream  Active JPEG stream.
     * @param string $payload Raw marker payload bytes.
     * @param int    $offset  Marker offset in stream.
     */
    public function handle(Stream $stream, string $payload, int $offset): void;
}
