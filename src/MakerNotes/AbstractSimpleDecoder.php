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
 * Shared decoder base for maker note vendors that only expose payload identity metadata.
 */
abstract class AbstractSimpleDecoder implements MakerNotesDecoderInterface
{
    /**
     * Returns the vendor name assigned to decoded maker note records.
     *
     * @return string
     */
    abstract protected function getVendorName(): string;

    /**
     * Creates a metadata record containing vendor, payload length, and SHA-1 digest.
     *
     * @param string      $raw   Raw maker note data stream captured from the image file.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier for the payload.
     *
     * @return MakerNotesRecord
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord(
            $this->getVendorName(),
            strlen($raw),
            sha1($raw),
        );
    }
}
