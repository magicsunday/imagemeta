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
 * Handles MPF APP2 segments.
 *
 * EXIF 3.0 §4.6.4 specifies MP Index (MPF) data in APP2 marker payloads.
 */
final readonly class MpfDocumentHandler implements MarkerHandlerInterface
{
    private const string MPF_SIGNATURE = "MPF\0";

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
        if (!str_starts_with($payload, self::MPF_SIGNATURE)) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
