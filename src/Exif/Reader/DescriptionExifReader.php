<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reader;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Microsoft\MicrosoftTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\LearningIntention;
use MagicSunday\ImageMeta\Value\Enum\LearningUsage;
use MagicSunday\ImageMeta\Value\LearningOptOutIn;
use MagicSunday\ImageMeta\Value\LearningOptOutInEntry;

use function array_find;
use function ord;
use function preg_match;
use function strlen;

/**
 * Reads document metadata, attribution, and EXIF version fields.
 *
 * Covers software, document name, copyright, artist, photographer, image editor,
 * image title, image description, image unique ID, EXIF version, and FlashPix version.
 */
final readonly class DescriptionExifReader
{
    /**
     * @param IfdValueReader  $reader     Value reader for IFD tag extraction.
     * @param ValueConverters $converters Value converter facade for EXIF type normalization.
     * @param Ifd             $ifd0       Root IFD of the TIFF structure.
     * @param Ifd|null        $exifIfd    Sub IFD containing EXIF-specific tags.
     */
    public function __construct(
        private IfdValueReader $reader,
        private ValueConverters $converters,
        private Ifd $ifd0,
        private ?Ifd $exifIfd,
    ) {
    }

    // ========================================================================
    // Document metadata
    // ========================================================================

    /**
     * Returns the software or firmware identifier reported by the image source.
     *
     * EXIF 3.0 §4.6.5.4.4 (Software) recommends recording the generating software
     * name and version in ASCII or UTF-8 with the terminating NUL accounted for in the count.
     */
    public function software(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::SOFTWARE);
    }

    /**
     * Returns the document name preferring EXIF 3.0 tags with XP fallbacks.
     */
    public function documentName(): ?string
    {
        $candidates = [
            [$this->ifd0, TiffTag::DOCUMENT_NAME],
            [$this->exifIfd, TiffTag::DOCUMENT_NAME],
            [$this->ifd0, ExifTag::IMAGE_TITLE],
            [$this->exifIfd, ExifTag::IMAGE_TITLE],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->reader->str($ifd, $tag);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the copyright notice string when present.
     *
     * EXIF 3.0 §4.6.5.4.7 represents empty or blank-filled copyright fields as unknown values.
     */
    public function copyright(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the artist tag value when present.
     *
     * EXIF 3.0 §4.6.5.4.6 requires Artist to be populated alongside
     * CameraOwnerName, Photographer, or ImageEditor. The closest available
     * attribution is returned when the primary tag is missing.
     */
    public function artist(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::ARTIST) ?? array_find(
            [
                $this->reader->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME),
                $this->reader->str($this->ifd0, ExifTag::PHOTOGRAPHER),
                $this->reader->str($this->exifIfd, ExifTag::PHOTOGRAPHER),
                $this->reader->str($this->ifd0, ExifTag::IMAGE_EDITOR),
                $this->reader->str($this->exifIfd, ExifTag::IMAGE_EDITOR),
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }

    /**
     * Returns the photographer name if present.
     *
     * EXIF 3.0 §4.6.6.9.9 recommends keeping the photographer attribution stable
     * and recording Artist alongside it.
     */
    public function photographer(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->reader->str($this->exifIfd, ExifTag::PHOTOGRAPHER);

        return $value ?? $this->reader->str(
            $this->ifd0,
            ExifTag::ARTIST
        );
    }

    /**
     * Returns the image editor attribution if present.
     *
     * EXIF 3.0 §4.6.6.9.10 captures the primary editor name and expects Artist to
     * be recorded when this tag is present.
     */
    public function imageEditor(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::IMAGE_EDITOR);

        return $value ?? $this->reader->str(
            $this->exifIfd,
            ExifTag::IMAGE_EDITOR
        );
    }

    // ========================================================================
    // Image description / title
    // ========================================================================

    /**
     * Returns the optional image title string.
     *
     * EXIF 3.0 §4.6.6.9.8 allows ASCII or UTF-8 text for ImageTitle and
     * treats blank fields as unknown.
     */
    public function imageTitle(): ?string
    {
        $value = $this->reader->str($this->ifd0, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->reader->str($this->exifIfd, ExifTag::IMAGE_TITLE);

        return $value ?? $this->reader->str(
            $this->ifd0,
            ExifTag::IMAGE_DESCRIPTION
        );
    }

    /**
     * Returns the EXIF image description when available.
     *
     * EXIF 3.0 §4.6.5.4.1 (ImageDescription) defines a free-form ASCII or UTF-8
     * description of the image content with the NUL terminator included in the stored count.
     */
    public function imageDescription(): ?string
    {
        return $this->reader->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * EXIF 3.0 §4.6.6.9.1 records a 128-bit UUID in
     * hexadecimal ASCII with a fixed count of 33 (including the terminator).
     * Version 4 UUIDs are recommended and the value should remain immutable.
     */
    public function imageUniqueId(): ?string
    {
        $value = $this->reader->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);

        // EXIF 3.0 §4.6.6.9.1: ImageUniqueID is a 128-bit UUID encoded as
        // 32 hexadecimal ASCII characters. Reject non-conformant values.
        if (($value === null) || (preg_match('/\A[0-9a-fA-F]{32}\z/', $value) !== 1)) {
            return null;
        }

        return $value;
    }

    // ========================================================================
    // EXIF version / FlashPix version
    // ========================================================================

    /**
     * Returns the normalized EXIF version string when present.
     *
     * EXIF 3.0 §4.6.6.1.1 (ExifVersion) treats a missing tag as non-conformance.
     */
    public function exifVersion(): ?string
    {
        $rawVersion = $this->reader->rawString($this->exifIfd, ExifTag::EXIF_VERSION);

        return $this->converters->toExifVersion($rawVersion);
    }

    /**
     * Returns the normalized FlashPix version string when present.
     *
     * EXIF 3.0 §4.6.6.1.2 (FlashpixVersion) limits this field to four ASCII digits.
     */
    public function flashpixVersion(): ?string
    {
        $value = $this->reader->rawString($this->exifIfd, ExifTag::FLASHPIX_VERSION);

        if ($value === null) {
            return '1.00';
        }

        return $this->converters->toExifVersion($value);
    }

    // ========================================================================
    // AI/ML learning opt-out/opt-in
    // ========================================================================

    /**
     * Returns the copyright holder's AI/ML training opt-out/opt-in intentions.
     *
     * EXIF 3.1 §4.6.5.4 (LearningOptOutIn) stores a sequence of (Usage, Intention)
     * byte pairs in an UNDEFINED tag. Pairs with reserved values are skipped.
     */
    public function learningOptOutIn(): ?LearningOptOutIn
    {
        $raw = $this->reader->rawString($this->exifIfd, ExifTag::LEARNING_OPT_OUT_IN);

        if ($raw === null || strlen($raw) < 2) {
            return null;
        }

        /** @var list<LearningOptOutInEntry> $entries */
        $entries = [];

        for ($i = 0; ($i + 1) < strlen($raw); $i += 2) {
            $usage     = LearningUsage::tryFrom(ord($raw[$i]));
            $intention = LearningIntention::tryFrom(ord($raw[$i + 1]));

            if ($usage !== null && $intention !== null) {
                $entries[] = new LearningOptOutInEntry($usage, $intention);
            }
        }

        if ($entries === []) {
            return null;
        }

        return new LearningOptOutIn($entries);
    }

    // ========================================================================
    // Windows XP tags
    // ========================================================================

    /**
     * Returns the Windows XP title decoded from UTF-16LE.
     */
    public function xpTitle(): ?string
    {
        $raw = $this->reader->rawString($this->ifd0, MicrosoftTag::XP_TITLE);

        return $raw !== null ? $this->reader->decodeUtf16Le($raw) : null;
    }

    /**
     * Returns the Windows XP comment decoded from UTF-16LE.
     */
    public function xpComment(): ?string
    {
        $raw = $this->reader->rawString($this->ifd0, MicrosoftTag::XP_COMMENT);

        return $raw !== null ? $this->reader->decodeUtf16Le($raw) : null;
    }

    /**
     * Returns the Windows XP author decoded from UTF-16LE.
     */
    public function xpAuthor(): ?string
    {
        $raw = $this->reader->rawString($this->ifd0, MicrosoftTag::XP_AUTHOR);

        return $raw !== null ? $this->reader->decodeUtf16Le($raw) : null;
    }

    /**
     * Returns the Windows XP keywords decoded from UTF-16LE.
     */
    public function xpKeywords(): ?string
    {
        $raw = $this->reader->rawString($this->ifd0, MicrosoftTag::XP_KEYWORDS);

        return $raw !== null ? $this->reader->decodeUtf16Le($raw) : null;
    }

    /**
     * Returns the Windows XP subject decoded from UTF-16LE.
     */
    public function xpSubject(): ?string
    {
        $raw = $this->reader->rawString($this->ifd0, MicrosoftTag::XP_SUBJECT);

        return $raw !== null ? $this->reader->decodeUtf16Le($raw) : null;
    }
}
