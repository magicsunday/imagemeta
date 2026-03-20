<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Support;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;

use function rtrim;
use function substr;
use function trim;

/**
 * Shared binary field reading methods used by maker note decoders.
 */
trait ReadsMakerNoteFields
{
    /**
     * Reads an unsigned 16-bit integer using the supplied byte order.
     */
    private function readU16(string $raw, int $offset, Endian $endian, string $context): int
    {
        $bytes = substr($raw, $offset, 2);

        return Unpack::int($endian === Endian::Little ? 'v' : 'n', $bytes, $context);
    }

    /**
     * Reads an unsigned 32-bit integer using the supplied byte order.
     */
    private function readU32(string $raw, int $offset, Endian $endian, string $context): int
    {
        $bytes = substr($raw, $offset, 4);

        return Unpack::int($endian === Endian::Little ? 'V' : 'N', $bytes, $context);
    }

    /**
     * Parses an ASCII-encoded value and normalizes it.
     */
    private function parseAscii(string $valueBytes): ?string
    {
        $value = trim(rtrim($valueBytes, "\0"));

        return $value === '' ? null : $value;
    }

    /**
     * Returns the byte width for a TIFF type used in maker notes.
     */
    private function typeSize(int $type): int
    {
        return match ($type) {
            TiffFieldType::Byte->value,
            TiffFieldType::Ascii->value => 1,
            TiffFieldType::Short->value => 2,
            TiffFieldType::Long->value,
            TiffFieldType::Float->value => 4,
            default                     => 0,
        };
    }
}
