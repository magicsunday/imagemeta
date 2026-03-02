<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use Closure;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;

use function str_starts_with;

/**
 * Handles JFIF APP0 segments.
 *
 * JFIF 1.02 (2009) §3 defines the APP0 marker payload starting with "JFIF\0".
 */
final readonly class JfifSegmentHandler implements MarkerHandlerInterface
{
    private const string JFIF_SIGNATURE = "JFIF\0";

    /**
     * @param Closure(string, int): void $handler
     */
    public function __construct(private Closure $handler)
    {
    }

    public function canHandle(int $marker): bool
    {
        return $marker === Marker::APP0;
    }

    public function handle(Stream $stream, string $payload, int $offset): void
    {
        if (!str_starts_with($payload, self::JFIF_SIGNATURE)) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
