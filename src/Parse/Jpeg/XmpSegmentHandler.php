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
 * Handles APP1 XMP payloads, including Extended XMP chunks.
 *
 * Adobe XMP Storage in Files defines APP1 base and Extended XMP signatures.
 */
final readonly class XmpSegmentHandler implements MarkerHandlerInterface
{
    private const string XMP_SIGNATURE = "http://ns.adobe.com/xap/1.0/\0";

    private const string EXTENDED_XMP_SIGNATURE = "http://ns.adobe.com/xmp/extension/\0";

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
        if (
            !str_starts_with($payload, self::XMP_SIGNATURE)
            && !str_starts_with($payload, self::EXTENDED_XMP_SIGNATURE)
        ) {
            return;
        }

        ($this->handler)($payload, $offset);
    }
}
