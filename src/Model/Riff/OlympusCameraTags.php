<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Riff;

/**
 * Immutable value object holding parsed Olympus Camera Tags from RIFF/AVI JUNK chunks.
 *
 * Olympus cameras embed metadata in JUNK chunks with an 'OLYMDigital Camera' signature.
 * Fields are at fixed byte offsets within the payload; all string fields are 24 bytes
 * wide, null/LF-terminated. FNumber is a rational64u (2x u32 LE).
 *
 * Typed properties provide structured access for StructuredMetadata factories.
 * The {@see $entries} array preserves all fields for format script rendering.
 */
final readonly class OlympusCameraTags
{
    /**
     * @param array<int, string> $entries   All parsed field entries (byte offset => display string).
     * @param string|null        $make      Camera make (offset 0x0012).
     * @param string|null        $model     Camera model (offset 0x002C).
     * @param float|null         $fNumber   F-number as rational64u (offset 0x005E).
     * @param string|null        $dateTime1 Date/time string 1 in ctime format (offset 0x0083).
     * @param string|null        $dateTime2 Date/time string 2 (offset 0x009D).
     */
    public function __construct(
        public array $entries,
        public ?string $make = null,
        public ?string $model = null,
        public ?float $fNumber = null,
        public ?string $dateTime1 = null,
        public ?string $dateTime2 = null,
    ) {
    }
}
