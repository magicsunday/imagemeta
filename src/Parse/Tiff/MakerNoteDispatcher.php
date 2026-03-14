<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function is_string;
use function rtrim;
use function sha1;
use function strlen;

/**
 * Dispatches maker note payloads to manufacturer-specific decoders and applies
 * the DNG MakerNoteSafety flag.
 *
 * EXIF 3.0 §4.6.6.4.1 (Table 4) defines the MakerNote tag semantics and the
 * MakerNoteSafety flag used to indicate whether in-place modification is safe.
 */
final class MakerNoteDispatcher
{
    /**
     * Resolves maker note metadata using the provided registry when available.
     *
     * @param string|null   $raw      Raw maker note bytes (null if no maker note present).
     * @param Registry|null $registry Optional maker notes registry.
     * @param Ifd           $ifd0     Primary image IFD.
     * @param Ifd|null      $exifIfd  EXIF IFD when present.
     */
    public function resolve(?string $raw, ?Registry $registry, Ifd $ifd0, ?Ifd $exifIfd): ?MakerNotesRecord
    {
        if ($raw === null) {
            return null;
        }

        if (!($registry instanceof Registry) || !($exifIfd instanceof Ifd)) {
            return $this->applySafety($this->digest($raw), $ifd0);
        }

        $make = $this->stringFromIfd($ifd0, ExifTag::MAKE);

        if ($make === null || $make === '') {
            return $this->applySafety($this->digest($raw), $ifd0);
        }

        $decoder = $registry->find($make);

        if (!$decoder instanceof MakerNotesDecoderInterface) {
            return $this->applySafety($this->digest($raw), $ifd0);
        }

        $model    = $this->stringFromIfd($ifd0, ExifTag::MODEL);
        $metadata = $decoder->decode($raw, $make, $model);

        return $this->applySafety($metadata, $ifd0);
    }

    /**
     * Creates a digest metadata instance for unknown maker notes.
     *
     * @param string $raw Raw maker note bytes.
     */
    private function digest(string $raw): MakerNotesRecord
    {
        return new MakerNotesRecord(
            'Unknown',
            strlen($raw),
            sha1($raw)
        );
    }

    /**
     * Applies the maker note safety flag to the provided metadata instance.
     *
     * @param MakerNotesRecord $metadata Maker note record to augment.
     * @param Ifd              $ifd0     Primary image IFD containing MakerNoteSafety tag.
     */
    private function applySafety(MakerNotesRecord $metadata, Ifd $ifd0): MakerNotesRecord
    {
        $entry = $ifd0->get(DngTag::MAKER_NOTE_SAFETY);
        $safe  = $entry instanceof IfdEntry ? ($entry->value === 1) : null;

        return new MakerNotesRecord(
            $metadata->vendor,
            $metadata->length,
            $metadata->sha1,
            $metadata->apple,
            $metadata->samsung,
            $metadata->dji,
            $safe,
        );
    }

    /**
     * Returns the trimmed string value for a specific tag within an IFD.
     *
     * @param Ifd|null $ifd IFD to search.
     * @param int      $tag Tag identifier.
     */
    private function stringFromIfd(?Ifd $ifd, int $tag): ?string
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!is_string($value)) {
            return null;
        }

        return rtrim($value, "\0");
    }
}
