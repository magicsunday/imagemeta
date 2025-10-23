<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use function sha1;
use function strlen;

/**
 * Decoder that extracts basic metadata about Nikon maker note payloads.
 */
final class NikonDecoder implements MakerNotesDecoderInterface
{
    /**
     * Creates a metadata value object that describes the Nikon maker note payload.
     *
     * @param string      $raw   Raw maker note data stream captured from the image file.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier for the payload.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesMetadata
    {
        return new MakerNotesMetadata(
            'Nikon',
            strlen($raw),
            sha1($raw)
        );
    }
}
