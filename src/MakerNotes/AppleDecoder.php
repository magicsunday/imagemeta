<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

/**
 * Decoder that returns basic metadata about the raw maker notes produced by Apple devices.
 */
final class AppleDecoder implements MakerNotesDecoderInterface
{
    /**
     * Creates a metadata map describing the Apple maker note payload.
     *
     * @param string      $raw   Raw maker note data stream.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier.
     *
     * @return array<string, mixed> Normalised metadata describing the vendor, payload length, and hash.
     */
    public function decode(string $raw, string $make, ?string $model): array
    {
        return [
            '_vendor' => 'Apple',
            '_length' => strlen($raw),
            '_sha1'   => sha1($raw, false),
        ];
    }
}
