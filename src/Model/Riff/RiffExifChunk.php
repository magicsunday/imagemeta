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
 * Fields from the RIFF-native LIST 'exif' sub-chunks.
 *
 * Unlike standard TIFF/EXIF, RIFF EXIF uses simple string sub-chunks
 * with 4-character tags: ecor (Make), emdl (Model), etim (TimeCreated),
 * eucm (UserComment), ever (ExifVersion), erel (RelatedImageFile),
 * emnt (MakerNotes).
 *
 * ExifTool RIFF.pm — %Image::ExifTool::RIFF::Exif tag table.
 */
final readonly class RiffExifChunk
{
    /**
     * @param string|null $make             Camera make (ecor chunk).
     * @param string|null $model            Camera model (emdl chunk).
     * @param string|null $timeCreated      Time created (etim chunk).
     * @param string|null $userComment      User comment (eucm chunk).
     * @param string|null $exifVersion      EXIF version string (ever chunk).
     * @param string|null $relatedImageFile Related image file (erel chunk).
     * @param string|null $makerNotes       Raw maker notes (emnt chunk).
     */
    public function __construct(
        public ?string $make = null,
        public ?string $model = null,
        public ?string $timeCreated = null,
        public ?string $userComment = null,
        public ?string $exifVersion = null,
        public ?string $relatedImageFile = null,
        public ?string $makerNotes = null,
    ) {
    }
}
