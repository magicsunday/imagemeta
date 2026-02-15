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
 * Handles EXIF audio APP2 segments.
 *
 * EXIF 3.0 §4.7.3 defines the APP2 audio stream payload signature and layout.
 */
final readonly class AudioStreamHandler implements MarkerHandlerInterface
{
    private const string AUDIO_SIGNATURE = "Exif\0\0Audio";

    /**
     * @param Closure(string, int): void $handler
     */
    public function __construct(private Closure $handler)
    {
    }

    public function canHandle(int $marker): bool
    {
        return $marker === Marker::APP2;
    }

    public function handle(Stream $stream, string $payload, int $offset): void
    {
        if (!str_starts_with($payload, self::AUDIO_SIGNATURE)) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
