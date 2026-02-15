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
 * Coordinates marker-handler strategies for JPEG APP segment processing.
 */
final class MarkerHandlerRegistry
{
    /**
     * @param list<MarkerHandlerInterface> $handlers
     */
    public function __construct(private array $handlers = [])
    {
    }

    /**
     * Registers one additional marker handler.
     */
    public function register(MarkerHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Indicates whether any registered handler supports the marker code.
     */
    public function supports(int $marker): bool
    {
        return array_any($this->handlers, fn ($handler) => $handler->canHandle($marker));
    }

    /**
     * Dispatches one payload to all handlers that participate for the marker.
     */
    public function dispatch(int $marker, Stream $stream, string $payload, int $offset): void
    {
        foreach ($this->handlers as $handler) {
            if (!$handler->canHandle($marker)) {
                continue;
            }

            $handler->handle($stream, $payload, $offset);
        }
    }
}
