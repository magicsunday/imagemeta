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
 * Handles EXIF APP1 segments.
 *
 * EXIF 3.0 §4.7.2 requires APP1 EXIF payloads to start with "Exif\0\0".
 */
final readonly class ExifSegmentHandler implements MarkerHandlerInterface
{
    private const string EXIF_SIGNATURE = "Exif\0\0";

    /**
     * @param Closure(string, int): void $handler
     */
    public function __construct(private Closure $handler)
    {
    }

    public function canHandle(int $marker): bool
    {
        return $marker === Marker::APP1;
    }

    public function handle(Stream $stream, string $payload, int $offset): void
    {
        if (!str_starts_with($payload, self::EXIF_SIGNATURE)) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
