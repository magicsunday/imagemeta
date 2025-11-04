<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Encapsulates container level characteristics such as codecs and format.
 */
final readonly class Container
{
    /**
     * Creates a container format metadata value object.
     *
     * @param string|null $format     Primary container format description.
     * @param string|null $encoder    Encoder or muxer responsible for the file.
     * @param int|null    $bitrate    Average bitrate of the container in bits per second.
     * @param string|null $videoCodec Video codec identifier when available.
     * @param string|null $audioCodec Audio codec identifier when available.
     */
    public function __construct(
        public ?string $format,
        public ?string $encoder,
        public ?int $bitrate,
        public ?string $videoCodec,
        public ?string $audioCodec,
    ) {
    }
}
