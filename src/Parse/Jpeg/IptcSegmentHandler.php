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
 * Handles IPTC APP13 segments.
 */
final readonly class IptcSegmentHandler implements MarkerHandlerInterface
{
    private const string IPTC_SIGNATURE = "Photoshop 3.0\0";

    /**
     * @param Closure(string, int): void $handler
     */
    public function __construct(private Closure $handler)
    {
    }

    public function canHandle(int $marker): bool
    {
        return $marker === Marker::APP13;
    }

    public function handle(Stream $stream, string $payload, int $offset): void
    {
        if (!str_starts_with($payload, self::IPTC_SIGNATURE)) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
