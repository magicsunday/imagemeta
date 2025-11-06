<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Exif;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\DngProfileGainTableTag;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\ExposureProgram;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\Orientation;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\WhiteBalance;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;

use function abs;
use function array_any;
use function array_key_exists;
use function array_map;
use function count;
use function iconv;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function ord;
use function preg_match;
use function preg_replace;
use function preg_split;
use function round;
use function rtrim;
use function spl_object_id;
use function sprintf;
use function sqrt;
use function str_pad;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function substr_count;
use function trim;

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 *
 * EXIF 3.0 §4 and Annex A summarise the logical grouping of tags mirrored by
 * the accessors provided in this value object.
 */
final readonly class ParsedExif
{
    private ?string $exifVersion;

    private bool $exifVersionMissingOrEmpty;

    private string $exifProfile;

    private bool $exifThreeOrNewer;

    /**
     * @var list<int>|null
     */
    private ?array $tiffEpStandardId;

    private ?string $tiffEpStandardIdString;

    /**
     * @var array{ifd:Ifd, offset:int, length:int}|null
     */
    private ?array $previewContext;

    /**
     * @param Ifd                   $ifd0           Root IFD of the TIFF structure.
     * @param Ifd|null              $exifIfd        Sub IFD containing EXIF-specific tags.
     * @param Ifd|null              $gpsIfd         Sub IFD containing GPS-related tags.
     * @param Ifd|null              $interopIfd     Sub IFD containing interoperability tags.
     * @param Ifd|null              $ifd1           Optional next IFD, typically thumbnails.
     * @param MakerNotesRecord|null $makerNotes     Decoded maker note metadata provided by vendor decoders.
     * @param list<Ifd>             $subsequentIfds Additional linked IFDs discovered via the next-pointer chain.
     * @param array<int, Ifd>       $subIfds        Parsed SubIFDs indexed by their file offsets.
     */
    public function __construct(
        public Ifd $ifd0,
        public ?Ifd $exifIfd,
        public ?Ifd $gpsIfd,
        public ?Ifd $interopIfd,
        public ?Ifd $ifd1,
        public ?MakerNotesRecord $makerNotes = null,
        public array $subsequentIfds = [],
        public array $subIfds = [],
    ) {
        $rawVersion                      = $this->rawString($this->exifIfd, ExifTag::EXIF_VERSION);
        $this->exifVersionMissingOrEmpty = $rawVersion === null || trim($rawVersion) === '';
        $this->exifVersion               = ValueConverters::toExifVersion($rawVersion);
        $this->exifProfile               = ExifCapabilities::fromVersion($this->exifVersion);
        $this->exifThreeOrNewer          = (float) $this->exifProfile >= 3.0;

        $tiffEpBytes                  = $this->numericList($this->exifIfd, ExifTag::TIFF_EP_STANDARD_ID);
        $tiffEpStandard               = ValueConverters::tiffEpStandardId($tiffEpBytes);
        $this->tiffEpStandardId       = $tiffEpStandard['bytes'] ?? null;
        $this->tiffEpStandardIdString = $tiffEpStandard['string'] ?? null;

        $this->previewContext = $this->resolvePreviewContext();
    }

    /**
     * Returns the decoded maker note metadata when a decoder is available.
     */
    public function makerNotes(): ?MakerNotesRecord
    {
        return $this->makerNotes;
    }

    /**
     * Indicates whether the maker note is considered safe to modify according to EXIF tag ExifTag::MAKER_NOTE_SAFETY.
     *
     * EXIF 3.0 §4.6.5 (Table 4) and EXIF 2.32 §4.6.5 define MakerNoteSafety as the
     * vendor-supplied flag denoting whether downstream applications may rewrite the
     * maker note block without violating interoperability.
     */
    public function makerNoteSafety(): ?bool
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::MAKER_NOTE_SAFETY);

        if ($value === null) {
            return null;
        }

        return ValueConverters::makerNoteSafety($value);
    }

    /**
     * Returns any additional image file directories linked from IFD0.
     *
     * @return list<Ifd>
     */
    public function subsequentIfds(): array
    {
        return $this->subsequentIfds;
    }

    /**
     * Returns the parsed SubIFDs keyed by their offset within the TIFF blob.
     *
     * @return array<int, Ifd>
     */
    public function subIfds(): array
    {
        return $this->subIfds;
    }

    /**
     * Returns the camera manufacturer string if present.
     *
     * @return string|null
     */
    public function cameraMake(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MAKE);
    }

    /**
     * Returns the camera model string if present.
     *
     * @return string|null
     */
    public function cameraModel(): ?string
    {
        return $this->str($this->ifd0, ExifTag::MODEL);
    }

    /**
     * Returns the aircraft manufacturer string if present.
     */
    public function aircraftMake(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::AIRCRAFT_MAKE);
    }

    /**
     * Returns the aircraft model string if present.
     */
    public function aircraftModel(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::AIRCRAFT_MODEL);
    }

    /**
     * Returns the lens model string if present.
     *
     * @return string|null
     */
    public function lensModel(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_MODEL);
    }

    /**
     * Returns the lens manufacturer string if present.
     *
     * @return string|null
     */
    public function lensMake(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_MAKE);
    }

    /**
     * Returns the camera owner name if present.
     *
     * @return string|null
     */
    public function ownerName(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::CAMERA_OWNER_NAME);
    }

    /**
     * Returns the camera body serial number if present.
     *
     * @return string|null
     */
    public function bodySerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::BODY_SERIAL_NUMBER);
    }

    /**
     * Returns the camera serial number, preferring the EXIF 3.0 tag when available.
     */
    public function cameraSerialNumber(): ?string
    {
        $serial = $this->str($this->exifIfd, ExifTag::CAMERA_SERIAL_NUMBER);

        return $serial ?? $this->bodySerialNumber();
    }

    /**
     * Returns the lens serial number if present.
     *
     * @return string|null
     */
    public function lensSerialNumber(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::LENS_SERIAL_NUMBER);
    }

    /**
     * Returns the lens specification describing focal and aperture range.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public function lensSpecification(): ?array
    {
        $values = $this->rationalList($this->exifIfd, ExifTag::LENS_SPECIFICATION);

        if (!is_array($values) || count($values) !== 4) {
            return null;
        }

        return [
            $values[0],
            $values[1],
            $values[2],
            $values[3],
        ];
    }

    /**
     * Returns the EXIF orientation enumeration when present.
     *
     * @return Orientation|null
     */
    public function orientation(): ?Orientation
    {
        $rawOrientation = $this->enumValue($this->ifd0, ExifTag::ORIENTATION);

        // Normalises numeric-string encodings emitted by some cameras.
        return Orientation::fromExifValue($rawOrientation);
    }

    /**
     * Returns the image width, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageWidth(): ?int
    {
        $width = $this->int($this->exifIfd, ExifTag::PIXEL_X_DIMENSION);

        return $width ?? $this->int($this->ifd0, ExifTag::IMAGE_WIDTH);
    }

    /**
     * Returns the image height, preferring the EXIF-specific tag and falling back to IFD0.
     *
     * @return int|null
     */
    public function imageHeight(): ?int
    {
        $height = $this->int($this->exifIfd, ExifTag::PIXEL_Y_DIMENSION);

        return $height ?? $this->int($this->ifd0, ExifTag::IMAGE_HEIGHT);
    }

    /**
     * Returns the colour space enumeration if present.
     */
    public function colorSpace(): ?ColorSpace
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::COLOR_SPACE);

        return ColorSpace::fromExifValue($value);
    }

    /**
     * Returns the image unique identifier if present.
     *
     * @return string|null
     */
    public function imageUniqueId(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_UNIQUE_ID);
    }

    /**
     * Returns the normalised EXIF version string, defaulting to '2.2' when missing.
     */
    public function exifVersion(): ?string
    {
        if ($this->exifVersion !== null) {
            return $this->exifVersion;
        }

        if ($this->exifVersionMissingOrEmpty) {
            return '2.2';
        }

        return null;
    }

    /**
     * Returns the normalised FlashPix version string when present.
     */
    public function flashpixVersion(): string
    {
        $value = $this->rawString($this->exifIfd, ExifTag::FLASHPIX_VERSION);

        if ($value === null) {
            return '1.00';
        }

        $trimmed = trim($value, "\0 ");

        if ($trimmed === '') {
            return '1.00';
        }

        $normalized = ValueConverters::toExifVersion($trimmed);

        return $normalized ?? '1.00';
    }

    /**
     * Returns the derived EXIF capability profile identifier.
     */
    public function exifProfile(): string
    {
        return $this->exifProfile;
    }

    /**
     * Returns the optional image title string.
     */
    public function imageTitle(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->exifIfd, ExifTag::IMAGE_TITLE);

        if ($value !== null) {
            return $value;
        }

        $value = $this->xpTitle();

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->ifd0, ExifTag::IMAGE_TITLE_LEGACY);

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::IMAGE_DESCRIPTION
        );
    }

    /**
     * Returns the document name preferring EXIF 3.0 tags with XP fallbacks.
     */
    public function documentName(): ?string
    {
        $candidates = [
            [$this->ifd0, ExifTag::DOCUMENT_NAME],
            [$this->exifIfd, ExifTag::DOCUMENT_NAME],
            [$this->ifd0, ExifTag::IMAGE_TITLE],
            [$this->exifIfd, ExifTag::IMAGE_TITLE],
            [$this->ifd0, ExifTag::IMAGE_TITLE_LEGACY],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->str($ifd, $tag);

            if ($value !== null) {
                return $value;
            }
        }

        return $this->xpSubject();
    }

    /**
     * Returns the EXIF image description or XPComment when available.
     */
    public function imageDescription(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);

        return $value ?? $this->xpComment();
    }

    /**
     * Returns the processing software string recorded during final image adjustments,
     * falling back to the legacy software tag for EXIF 2.x payloads.
     */
    public function processingSoftware(): ?string
    {
        $value = null;

        if ($this->exifThreeOrNewer) {
            $value = $this->str($this->ifd0, ExifTag::PROCESSING_SOFTWARE);
        }

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::SOFTWARE
        );
    }

    /**
     * Mirrors the legacy resolver accessor for the software tag.
     */
    public function software(): ?string
    {
        return $this->processingSoftware();
    }

    /**
     * Returns the legacy host computer string retained for pre-EXIF 3.0 metadata.
     */
    public function hostComputer(): ?string
    {
        if ($this->exifThreeOrNewer) {
            return null;
        }

        return $this->str($this->ifd0, ExifTag::HOST_COMPUTER);
    }

    /**
     * Returns the photographer name if present.
     */
    public function photographer(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->exifIfd, ExifTag::PHOTOGRAPHER);

        if ($value !== null) {
            return $value;
        }

        $value = $this->xpAuthor();

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->ifd0, ExifTag::PHOTOGRAPHER_LEGACY);

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::ARTIST
        );
    }

    /**
     * Returns the image editor attribution if present.
     */
    public function imageEditor(): ?string
    {
        $value = $this->str($this->ifd0, ExifTag::IMAGE_EDITOR);

        if ($value !== null) {
            return $value;
        }

        $value = $this->str($this->exifIfd, ExifTag::IMAGE_EDITOR);

        if ($value !== null) {
            return $value;
        }

        $value = $this->xpAuthor();

        return $value ?? $this->str(
            $this->ifd0,
            ExifTag::IMAGE_EDITOR_LEGACY
        );
    }

    /**
     * Returns the decoded Microsoft XPTitle value.
     */
    public function xpTitle(): ?string
    {
        return $this->str($this->ifd0, ExifTag::XP_TITLE);
    }

    /**
     * Returns the decoded Microsoft XPComment value.
     */
    public function xpComment(): ?string
    {
        return $this->str($this->ifd0, ExifTag::XP_COMMENT);
    }

    /**
     * Returns the decoded Microsoft XPAuthor value.
     */
    public function xpAuthor(): ?string
    {
        return $this->str($this->ifd0, ExifTag::XP_AUTHOR);
    }

    /**
     * Returns the decoded Microsoft XPSubject value.
     */
    public function xpSubject(): ?string
    {
        return $this->str($this->ifd0, ExifTag::XP_SUBJECT);
    }

    /**
     * Returns the decoded Microsoft XPKeywords value as a list.
     *
     * @return list<string>|null
     */
    public function xpKeywords(): ?array
    {
        $raw = $this->str($this->ifd0, ExifTag::XP_KEYWORDS);

        if ($raw === null) {
            return null;
        }

        $parts = preg_split('/;+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($parts)) {
            return null;
        }

        $keywords = [];

        foreach ($parts as $part) {
            $keyword = trim($part);
            if ($keyword !== '') {
                $keywords[] = $keyword;
            }
        }

        return $keywords === [] ? null : $keywords;
    }

    /**
     * Returns the tile width defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     * For thumbnail tile width, use thumbnailTileWidth().
     */
    public function tileWidth(): ?int
    {
        return $this->int($this->ifd0, ExifTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileLength for tiled image storage.
     * For thumbnail tile length, use thumbnailTileLength().
     */
    public function tileLength(): ?int
    {
        return $this->int($this->ifd0, ExifTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     * For thumbnail tile offsets, use thumbnailTileOffsets().
     *
     * @return list<int>|null
     */
    public function tileOffsets(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     * For thumbnail tile byte counts, use thumbnailTileByteCounts().
     *
     * @return list<int>|null
     */
    public function tileByteCounts(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets defined for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §8 define StripOffsets for strip-based image storage.
     * For thumbnail strip offsets, use thumbnailStripOffsets().
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the primary image data (IFD0).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §8 define StripByteCounts for strip-based image storage.
     * For thumbnail strip byte counts, use thumbnailStripByteCounts().
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the transfer function lookup table when available.
     *
     * @return list<int>|null
     */
    public function transferFunction(): ?array
    {
        return $this->numericList($this->ifd0, ExifTag::TRANSFER_FUNCTION);
    }

    /**
     * Indicates whether a JPEG thumbnail is referenced by the EXIF structure.
     *
     * EXIF 3.0 §4.6.4 and EXIF 2.32 §4.6.4 describe the JPEG thumbnail tags and require
     * both offset and length to be populated for a valid embedded thumbnail.
     */
    public function hasThumbnail(): bool
    {
        $offset = $this->thumbnailJpegInterchangeFormat();
        $length = $this->thumbnailJpegInterchangeFormatLength();

        if ($offset === null || $length === null) {
            return false;
        }

        return $length > 0;
    }

    /**
     * Returns the JPEG thumbnail offset from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.4 (Table 3) and EXIF 2.32 §4.6.4 document JPEGInterchangeFormat as
     * the byte offset to embedded JPEG thumbnails stored in IFD1 (the first IFD after IFD0).
     */
    public function thumbnailJpegInterchangeFormat(): ?int
    {
        return $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG thumbnail byte length from the dedicated thumbnail IFD (IFD1).
     *
     * EXIF 3.0 §4.6.4 (Table 3) and EXIF 2.32 §4.6.4 define JPEGInterchangeFormatLength
     * as the size in bytes of the JPEG thumbnail stream in IFD1.
     */
    public function thumbnailJpegInterchangeFormatLength(): ?int
    {
        return $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the compression enum describing the JPEG thumbnail stored in IFD1.
     *
     * EXIF 3.0 §4.6.4 and EXIF 2.32 §4.6.4 map the Compression tag in the
     * thumbnail IFD to the embedded preview codec.
     */
    public function thumbnailCompression(): ?Compression
    {
        return Compression::fromExifValue($this->enumValue($this->ifd1, ExifTag::COMPRESSION));
    }

    /**
     * Returns the tile width defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileWidth for tiled image storage.
     */
    public function thumbnailTileWidth(): ?int
    {
        return $this->int($this->ifd1, ExifTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the thumbnail image data (IFD1).
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileLength for tiled image storage.
     */
    public function thumbnailTileLength(): ?int
    {
        return $this->int($this->ifd1, ExifTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileOffsets for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileOffsets(): ?array
    {
        return $this->numericList($this->ifd1, ExifTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts for the thumbnail image when stored using TIFF tiles.
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §15 define TileByteCounts for tiled image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailTileByteCounts(): ?array
    {
        return $this->numericList($this->ifd1, ExifTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §8 define StripOffsets for strip-based image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailStripOffsets(): ?array
    {
        return $this->numericList($this->ifd1, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the thumbnail image when stored using TIFF strips.
     *
     * EXIF 3.0 §4.6.4 and TIFF 6.0 §8 define StripByteCounts for strip-based image storage.
     *
     * @return list<int>|null
     */
    public function thumbnailStripByteCounts(): ?array
    {
        return $this->numericList($this->ifd1, ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Indicates whether an EXIF 3.0 preview image is referenced.
     *
     * EXIF 3.0 §4.6.12 requires both PreviewImageStart and PreviewImageLength to be
     * populated for a valid preview entry.
     */
    public function hasPreviewImage(): bool
    {
        $context = $this->previewContext;
        if ($context !== null) {
            return $context['length'] > 0;
        }

        if (array_any(
            $this->previewCandidateIfds(),
            static fn (Ifd $ifd): bool => $ifd->get(ExifTag::PREVIEW_IMAGE_START) instanceof IfdEntry
                || $ifd->get(ExifTag::PREVIEW_IMAGE_LENGTH) instanceof IfdEntry,
        )) {
            return false;
        }

        $otherPreviewTags = [
            ExifTag::PREVIEW_IMAGE_COMPRESSION,
            ExifTag::PREVIEW_IMAGE_SCALE,
            ExifTag::PREVIEW_IMAGE_WIDTH,
            ExifTag::PREVIEW_IMAGE_HEIGHT,
            ExifTag::PREVIEW_IMAGE_ENCODING,
            ExifTag::PREVIEW_IMAGE_MIME_TYPE,
            ExifTag::PREVIEW_IMAGE_BIT_DEPTH,
            ExifTag::PREVIEW_IMAGE_COLOR_SPACE,
        ];

        if (array_any(
            $this->previewCandidateIfds(),
            static fn (Ifd $ifd): bool => array_any(
                $otherPreviewTags,
                static fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry,
            ),
        )) {
            return false;
        }

        return false;
    }

    /**
     * Returns the preview image offset stored in the EXIF 3.0 preview tags.
     *
     * EXIF 3.0 §4.6.12 introduces PreviewImageStart as the byte offset to the optional
     * high-quality preview, extending the thumbnail handling defined in EXIF 2.32.
     */
    public function previewImageOffset(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $context['offset'];
    }

    /**
     * Returns the preview image byte length stored in the EXIF 3.0 preview tags.
     *
     * EXIF 3.0 §4.6.12 defines PreviewImageLength as the byte size of the preview payload
     * adjacent to PreviewImageStart.
     */
    public function previewImageLength(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $context['length'];
    }

    /**
     * @return list<Ifd>
     *
     * EXIF 3.0 §4.6.12 permits preview tags inside the Exif IFD or auxiliary SubIFDs, so
     * the lookup inspects those directories in the order recommended by the spec.
     */
    private function previewCandidateIfds(): array
    {
        $candidates = [];

        if ($this->exifIfd instanceof Ifd) {
            $candidates[] = $this->exifIfd;
        }

        foreach ($this->fallbackIfds(includePrimaryThumbnail: false, includeIfd0: true) as $ifd) {
            $candidates[] = $ifd;
        }

        return $candidates;
    }

    /**
     * Resolves the EXIF 3.0 preview descriptor from the candidate directories.
     *
     * EXIF 3.0 §4.6.12 stores preview metadata within the Exif or auxiliary IFDs, so the
     * routine walks those directories until it finds a consistent offset/length pair.
     *
     * @return array{ifd:Ifd, offset:int, length:int}|null
     */
    private function resolvePreviewContext(): ?array
    {
        foreach ($this->previewCandidateIfds() as $ifd) {
            $offset = $this->int($ifd, ExifTag::PREVIEW_IMAGE_START);
            $length = $this->int($ifd, ExifTag::PREVIEW_IMAGE_LENGTH);

            if ($offset === null && $length === null) {
                continue;
            }

            if ($offset === null) {
                continue;
            }

            if ($length === null) {
                continue;
            }

            if ($offset <= 0) {
                continue;
            }

            if ($length <= 0) {
                continue;
            }

            return [
                'ifd'    => $ifd,
                'offset' => $offset,
                'length' => $length,
            ];
        }

        return null;
    }

    /**
     * Returns the preview image width in pixels.
     *
     * EXIF 3.0 §4.6.12 lists PreviewImageWidth among the supplemental preview tags.
     */
    public function previewImageWidth(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->int($context['ifd'], ExifTag::PREVIEW_IMAGE_WIDTH);
    }

    /**
     * Returns the preview image height in pixels.
     *
     * EXIF 3.0 §4.6.12 lists PreviewImageHeight alongside the preview geometry tags.
     */
    public function previewImageHeight(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->int($context['ifd'], ExifTag::PREVIEW_IMAGE_HEIGHT);
    }

    /**
     * Returns the preview image encoding identifier when present.
     *
     * EXIF 3.0 §4.6.12 exposes PreviewImageEncoding for vendor-defined encoding hints.
     */
    public function previewImageEncoding(): ?string
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->str($context['ifd'], ExifTag::PREVIEW_IMAGE_ENCODING);
    }

    /**
     * Returns the preview image MIME type.
     *
     * EXIF 3.0 §4.6.12 defines PreviewImageMimeType as the IANA media type identifier.
     */
    public function previewImageMimeType(): ?string
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->str($context['ifd'], ExifTag::PREVIEW_IMAGE_MIME_TYPE);
    }

    /**
     * Returns the preview image bit depth when provided by the metadata.
     *
     * EXIF 3.0 §4.6.12 introduces PreviewImageBitDepth for the preview container.
     */
    public function previewImageBitDepth(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->int($context['ifd'], ExifTag::PREVIEW_IMAGE_BIT_DEPTH);
    }

    /**
     * Returns the preview image compression identifier when provided.
     *
     * EXIF 3.0 §4.6.12 documents PreviewImageCompression for codec identification.
     */
    public function previewImageCompression(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        $compression = $this->int($context['ifd'], ExifTag::PREVIEW_IMAGE_COMPRESSION);

        return $compression !== null && $compression > 0 ? $compression : null;
    }

    /**
     * Returns the strip offsets for the preview image when stored without a contiguous payload.
     *
     * EXIF 3.0 §4.6.12 allows preview descriptors to omit PreviewImageStart/Length when
     * the image data follows the TIFF strip/tile model inherited from EXIF 2.32 §4.6.4.
     *
     * @return list<int>|null
     */
    public function previewImageStripOffsets(): ?array
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->numericList($context['ifd'], ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the preview image when stored without a contiguous payload.
     *
     * EXIF 3.0 §4.6.12 reuses the TIFF strip layout from EXIF 2.32 §4.6.4.
     *
     * @return list<int>|null
     */
    public function previewImageStripByteCounts(): ?array
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->numericList($context['ifd'], ExifTag::STRIP_BYTE_COUNTS);
    }

    /**
     * Returns the tile offsets for the preview image when stored in tiles.
     *
     * EXIF 3.0 §4.6.12 keeps compatibility with the TIFF tile storage described in
     * EXIF 2.32 §4.6.4.
     *
     * @return list<int>|null
     */
    public function previewImageTileOffsets(): ?array
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->numericList($context['ifd'], ExifTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts for the preview image when stored in tiles.
     *
     * EXIF 3.0 §4.6.12 allows the TIFF tile model to be reused for previews.
     *
     * @return list<int>|null
     */
    public function previewImageTileByteCounts(): ?array
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->numericList($context['ifd'], ExifTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the preview image scale factor when provided.
     *
     * EXIF 3.0 §4.6.12 defines PreviewImageScale as the ratio between preview and primary image.
     */
    public function previewImageScale(): ?float
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        $scale = $this->rational($context['ifd'], ExifTag::PREVIEW_IMAGE_SCALE);

        if ($scale === null) {
            return null;
        }

        return $scale > 0.0 ? $scale : null;
    }

    /**
     * Returns the preview image colour space identifier when present.
     *
     * EXIF 3.0 §4.6.12 introduces PreviewImageColorSpace to describe the preview gamut.
     */
    public function previewColorSpace(): ?int
    {
        $context = $this->previewContext;
        if ($context === null) {
            return null;
        }

        return $this->int($context['ifd'], ExifTag::PREVIEW_IMAGE_COLOR_SPACE);
    }

    /**
     * Returns the raw preview modification datetime string.
     *
     * EXIF 3.0 §4.6.12 specifies PreviewDateTime for auditing preview updates.
     */
    public function previewDateTimeRaw(): ?string
    {
        return $this->rawString($this->exifIfd, ExifTag::PREVIEW_DATE_TIME);
    }

    /**
     * Returns the raw preview digitised datetime string.
     *
     * EXIF 3.0 §4.6.12 defines PreviewDateTimeDigitized alongside the modification time.
     */
    public function previewDateTimeDigitizedRaw(): ?string
    {
        return $this->rawString($this->exifIfd, ExifTag::PREVIEW_DATE_TIME_DIGITIZED);
    }

    /**
     * Returns the preview modification datetime as an immutable value.
     */
    public function previewDateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime($this->previewDateTimeRaw(), null, null);
    }

    /**
     * Returns the preview digitised datetime as an immutable value.
     */
    public function previewDateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime($this->previewDateTimeDigitizedRaw(), null, null);
    }

    /**
     * Returns the reference black and white point values as floating point numbers.
     *
     * @return list<float>|null
     */
    public function referenceBlackWhite(): ?array
    {
        return $this->rationalList($this->ifd0, ExifTag::REFERENCE_BLACK_WHITE);
    }

    /**
     * Returns the copyright notice string when present.
     */
    public function copyright(): ?string
    {
        return $this->str($this->ifd0, ExifTag::COPYRIGHT);
    }

    /**
     * Returns the sequential image number when provided by the camera.
     */
    public function imageNumber(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::IMAGE_NUMBER);
    }

    /**
     * Returns the security classification label recorded in the metadata.
     */
    public function securityClassification(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::SECURITY_CLASSIFICATION);
    }

    /**
     * Returns the free-form image history string when present.
     */
    public function imageHistory(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::IMAGE_HISTORY);
    }

    /**
     * Returns the components configuration array when present.
     *
     * @return list<int>|null
     */
    public function componentsConfiguration(): ?array
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfiguration($value);
    }

    /**
     * Returns the component configuration labels in human readable form.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfigurationLabels($value);
    }

    /**
     * Returns the component configuration as a formatted string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        $value = $this->componentsInput($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);

        return ValueConverters::componentsConfigurationDescription($value);
    }

    /**
     * Returns the TIFF/EP standard identifier as a list of bytes.
     *
     * @return list<int>|null
     */
    public function tiffEpStandardId(): ?array
    {
        return $this->tiffEpStandardId;
    }

    /**
     * Returns the TIFF/EP standard identifier as a normalised string representation.
     */
    public function tiffEpStandardIdString(): ?string
    {
        return $this->tiffEpStandardIdString;
    }

    /**
     * Returns the compressed bits per pixel ratio.
     */
    public function compressedBitsPerPixel(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::COMPRESSED_BITS_PER_PIXEL);
    }

    /**
     * Returns the user comment string after decoding the EXIF prefix.
     */
    public function userComment(): ?string
    {
        $raw = $this->rawUserComment();

        return $raw !== null ? $this->decodeUserComment($raw) : null;
    }

    /**
     * Returns the encoding declared in the EXIF user comment prefix.
     */
    public function userCommentEncoding(): ?string
    {
        $raw = $this->rawUserComment();
        if ($raw === null) {
            return null;
        }

        if (strlen($raw) < 8) {
            return $this->inferUserCommentEncoding($raw);
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);
        $hasKnownPrefix    = $canonicalEncoding !== '';
        $content           = $hasKnownPrefix ? substr($raw, 8) : $raw;
        $hasContent        = trim($content, "\0 ") !== '';

        if (!$hasKnownPrefix) {
            return $this->inferUserCommentEncoding($content);
        }

        return $hasContent ? $canonicalEncoding : null;
    }

    /**
     * Provides the declared user comment encoding falling back to ASCII when undecorated content exists.
     */
    public function userCommentEncodingBestEffort(): ?string
    {
        $encoding = $this->userCommentEncoding();
        if ($encoding !== null) {
            return $encoding;
        }

        $raw = $this->rawUserComment();
        if ($raw === null) {
            return null;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);
        $hasKnownPrefix    = $canonicalEncoding !== '';
        $content           = $hasKnownPrefix ? substr($raw, 8) : $raw;

        return $this->inferUserCommentEncoding($content);
    }

    /**
     * Normalises known EXIF user comment markers to their canonical identifiers.
     */
    private function canonicalUserCommentMarker(string $prefix): string
    {
        if ($prefix === "\0\0\0\0\0\0\0\0") {
            return 'UNDEFINED';
        }

        $encoding   = strtoupper(trim($prefix, "\0 "));
        $normalized = str_replace(['-', ' '], '', $encoding);

        return match ($normalized) {
            'ASCII'   => 'ASCII',
            'JIS'     => 'JIS',
            'UNICODE' => 'UNICODE',
            'UNDEFINED', 'UNDEF', 'UTF8' => 'UNDEFINED',
            default => '',
        };
    }

    /**
     * Returns the interoperability version string when present.
     */
    public function interopVersion(): ?string
    {
        $raw = $this->rawString($this->interopIfd, ExifTag::INTEROPERABILITY_VERSION);

        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw, "\0 ");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns the related image file format declared in the interoperability IFD.
     */
    public function relatedImageFileFormat(): ?string
    {
        return $this->str($this->interopIfd, ExifTag::RELATED_IMAGE_FILE_FORMAT);
    }

    /**
     * Returns the related image width declared in the interoperability IFD.
     */
    public function relatedImageWidth(): ?int
    {
        return $this->int($this->interopIfd, ExifTag::RELATED_IMAGE_WIDTH);
    }

    /**
     * Returns the related image length declared in the interoperability IFD.
     */
    public function relatedImageLength(): ?int
    {
        return $this->int($this->interopIfd, ExifTag::RELATED_IMAGE_LENGTH);
    }

    /**
     * Returns the interlace flag when recorded.
     */
    public function interlace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::INTERLACE);
    }

    /**
     * Returns the spectral sensitivity description.
     */
    public function spectralSensitivity(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::SPECTRAL_SENSITIVITY);
    }

    /**
     * Returns the opto-electronic conversion function data.
     *
     * @return array{payload:string, matrix:(array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null)}|null
     */
    public function oecf(): ?array
    {
        $payload = $this->oecfPayload();
        if ($payload === null) {
            return null;
        }

        return [
            'payload' => $payload,
            'matrix'  => ValueConverters::decodeOecf($payload),
        ];
    }

    /**
     * Returns the raw opto-electronic conversion function payload.
     */
    public function oecfPayload(): ?string
    {
        return $this->rawString($this->exifIfd, ExifTag::OECF);
    }

    /**
     * Returns the ISO sensitivity value if present.
     *
     * @return int|null
     */
    public function iso(): ?int
    {
        $sensitivityType = $this->int($this->exifIfd, ExifTag::SENSITIVITY_TYPE);
        if ($sensitivityType !== null) {
            foreach ($this->sensitivityTagPriority($sensitivityType) as $tag) {
                $value = $this->int($this->exifIfd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        $candidates = [
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->exifIfd, ExifTag::ISO_SPEED_RATINGS_LEGACY],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
            [$this->ifd0, ExifTag::ISO_SPEED_RATINGS_LEGACY],
        ];

        foreach ($candidates as [$ifd, $tag]) {
            $value = $this->int($ifd, $tag);
            if ($value !== null) {
                return $value;
            }
        }

        $fallbackTags = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::ISO_SPEED,
            ExifTag::ISO_SPEED_RATINGS_LEGACY,
            ExifTag::EXPOSURE_INDEX,
        ];

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            foreach ($fallbackTags as $tag) {
                $value = $this->int($ifd, $tag);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Returns the ISO sensitivity using a broader set of fallbacks for non-standard encodings.
     */
    public function isoBestEffort(): ?int
    {
        $iso = $this->iso();
        if ($iso !== null) {
            return $iso;
        }

        $tagPriority = [
            ExifTag::STANDARD_OUTPUT_SENSITIVITY,
            ExifTag::RECOMMENDED_EXPOSURE_INDEX,
            ExifTag::ISO_SPEED,
            ExifTag::PHOTOGRAPHIC_SENSITIVITY,
            ExifTag::ISO_SPEED_RATINGS_LEGACY,
            ExifTag::EXPOSURE_INDEX,
        ];

        $fallbacks = [
            [$this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            [$this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            [$this->exifIfd, ExifTag::ISO_SPEED],
            [$this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->exifIfd, ExifTag::ISO_SPEED_RATINGS_LEGACY],
            [$this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd0, ExifTag::ISO_SPEED],
            [$this->ifd0, ExifTag::ISO_SPEED_RATINGS_LEGACY],
            [$this->ifd1, ExifTag::PHOTOGRAPHIC_SENSITIVITY],
            [$this->ifd1, ExifTag::ISO_SPEED],
            [$this->ifd1, ExifTag::ISO_SPEED_RATINGS_LEGACY],
            [$this->exifIfd, ExifTag::EXPOSURE_INDEX],
            [$this->ifd0, ExifTag::EXPOSURE_INDEX],
            [$this->ifd1, ExifTag::EXPOSURE_INDEX],
        ];

        foreach ($fallbacks as [$ifd, $tag]) {
            $value = $this->coerceIntValue($this->value($ifd, $tag));
            if ($value !== null) {
                return $value;
            }
        }

        foreach ($this->subIfds as $subIfd) {
            foreach ($tagPriority as $tag) {
                $value = $this->coerceIntValue($this->value($subIfd, $tag));
                if ($value !== null) {
                    return $value;
                }
            }
        }

        if ($this->subsequentIfds !== []) {
            $additionalTags = [
                ExifTag::STANDARD_OUTPUT_SENSITIVITY,
                ExifTag::RECOMMENDED_EXPOSURE_INDEX,
                ExifTag::ISO_SPEED,
                ExifTag::PHOTOGRAPHIC_SENSITIVITY,
                ExifTag::ISO_SPEED_RATINGS_LEGACY,
                ExifTag::EXPOSURE_INDEX,
            ];

            foreach ($this->subsequentIfds as $ifd) {
                if ($this->ifd1 instanceof Ifd && $ifd === $this->ifd1) {
                    continue;
                }

                foreach ($additionalTags as $tag) {
                    $value = $this->coerceIntValue($this->value($ifd, $tag));
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Maps the EXIF sensitivity type enumeration to ISO-related tag priorities.
     *
     * @return list<int>
     */
    private function sensitivityTagPriority(int $type): array
    {
        return match ($type) {
            1       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY],
            2       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            3       => [ExifTag::ISO_SPEED],
            4       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX],
            5       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::ISO_SPEED],
            6       => [ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            7       => [ExifTag::STANDARD_OUTPUT_SENSITIVITY, ExifTag::RECOMMENDED_EXPOSURE_INDEX, ExifTag::ISO_SPEED],
            default => [],
        };
    }

    /**
     * Returns the ISO latitude yyy value when present.
     */
    public function isoSpeedLatitudeYyy(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_YYY);
    }

    /**
     * Returns the ISO latitude zzz value when present.
     */
    public function isoSpeedLatitudeZzz(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::ISO_SPEED_LATITUDE_ZZZ);
    }

    /**
     * Returns the exposure time in seconds if available.
     *
     * @return float|null
     */
    public function exposureTime(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_TIME);
    }

    /**
     * Returns the APEX shutter speed value when available.
     */
    public function shutterSpeedValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);
    }

    /**
     * Returns the shutter speed in seconds derived from the APEX value.
     */
    public function shutterSpeedSeconds(): ?float
    {
        $raw = $this->normalisedValue($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

        if ($raw === null) {
            return null;
        }

        return ValueConverters::apexShutterSpeedToSeconds($raw);
    }

    /**
     * Returns the aperture (f-number) if available.
     *
     * @return float|null
     */
    public function fNumber(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::F_NUMBER);
    }

    /**
     * Returns the APEX aperture value when present.
     */
    public function apertureValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::APERTURE_VALUE);
    }

    /**
     * Returns the focal length in millimetres if available.
     *
     * @return float|null
     */
    public function focalLengthMm(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_LENGTH);
    }

    /**
     * Returns the focal length in 35mm equivalent if available.
     *
     * @return int|null
     */
    public function focalLength35Mm(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FOCAL_LENGTH_IN_35MM_FILM);
    }

    /**
     * Returns the camera exposure program enumeration if present.
     */
    public function exposureProgram(): ?ExposureProgram
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::EXPOSURE_PROGRAM);

        return ExposureProgram::fromExifValue($value);
    }

    /**
     * Returns the metering mode enumeration if present.
     *
     * @return MeteringMode|null
     */
    public function meteringMode(): ?MeteringMode
    {
        $rawMeteringMode = $this->enumValue($this->exifIfd, ExifTag::METERING_MODE);

        return MeteringMode::fromExifValue($rawMeteringMode);
    }

    /**
     * Returns the flash status flags if present.
     *
     * @return int|null
     */
    public function flash(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FLASH);
    }

    /**
     * Returns the white balance enumeration if present.
     */
    public function whiteBalance(): ?WhiteBalance
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::WHITE_BALANCE);

        return WhiteBalance::fromExifValue($value);
    }

    /**
     * Returns the exposure bias value in EV if present.
     *
     * @return float|null
     */
    public function exposureBias(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_BIAS_VALUE);
    }

    /**
     * Returns the scene brightness value (APEX) if present.
     *
     * @return float|null
     */
    public function brightnessValue(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::BRIGHTNESS_VALUE);
    }

    /**
     * Returns the maximum aperture value (APEX) if present.
     *
     * @return float|null
     */
    public function maxApertureApex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::MAX_APERTURE_VALUE);
    }

    /**
     * Returns the flash energy when provided.
     */
    public function flashEnergy(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FLASH_ENERGY);
    }

    /**
     * Returns the focal plane X resolution.
     */
    public function focalPlaneXResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_X_RESOLUTION);
    }

    /**
     * Returns the focal plane Y resolution.
     */
    public function focalPlaneYResolution(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::FOCAL_PLANE_Y_RESOLUTION);
    }

    /**
     * Returns the focal plane resolution unit.
     */
    public function focalPlaneResolutionUnit(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::FOCAL_PLANE_RESOLUTION_UNIT);
    }

    /**
     * Returns the subject location coordinates when supplied.
     *
     * @return list<int>|null
     */
    public function subjectLocation(): ?array
    {
        return $this->numericList($this->exifIfd, ExifTag::SUBJECT_LOCATION);
    }

    /**
     * Returns the exposure index value.
     */
    public function exposureIndex(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::EXPOSURE_INDEX);
    }

    /**
     * Returns the related sound file reference.
     */
    public function relatedSoundFile(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::RELATED_SOUND_FILE);
    }

    /**
     * Returns the decoded spatial frequency response table.
     *
     * @return array{columns:int, rows:int, labels:array{columns:list<string>, rows:list<string>}, values:list<list<float|null>>}|null
     */
    public function spatialFrequencyResponse(): ?array
    {
        $payload = $this->rawString($this->exifIfd, ExifTag::SPATIAL_FREQUENCY_RESPONSE);

        return ValueConverters::decodeSpatialFrequencyResponse($payload);
    }

    /**
     * Returns the Epson Print Image Matching parameter block when available.
     *
     * @return array{header:string, version:string, parameters:list<array{id:int, value:int}>}|null
     */
    public function printImageMatching(): ?array
    {
        $payload = $this->rawString($this->exifIfd, ExifTag::PRINT_IMAGE_MATCHING);

        return ValueConverters::decodePrintImageMatching($payload);
    }

    /**
     * Returns the DNG camera calibration signature recorded by the capture device.
     */
    public function cameraCalibrationSignature(): ?string
    {
        $signature = $this->str($this->exifIfd, ExifTag::CAMERA_CALIBRATION_SIGNATURE);

        return $signature ?? $this->str($this->ifd0, ExifTag::CAMERA_CALIBRATION_SIGNATURE);
    }

    /**
     * Returns the DNG profile calibration signature when available.
     */
    public function profileCalibrationSignature(): ?string
    {
        $signature = $this->str($this->exifIfd, ExifTag::PROFILE_CALIBRATION_SIGNATURE);

        return $signature ?? $this->str($this->ifd0, ExifTag::PROFILE_CALIBRATION_SIGNATURE);
    }

    /**
     * Returns the hue/saturation/value profile adjustment maps.
     *
     * @return array{
     *     dimensions:list<int>|null,
     *     encodings:list<int>|null,
     *     map1:list<float>|null,
     *     map2:list<float>|null,
     *     map3:list<float>|null,
     * }|null
     */
    public function profileHueSatMap(): ?array
    {
        foreach ($this->profileIfds() as $ifd) {
            $dimensions = $this->numericList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DIMS);
            $encodings  = $this->numericList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_ENCODINGS);
            $map1       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_1);
            $map2       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_2);
            $map3       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_3);

            if ($dimensions === null && $encodings === null && $map1 === null && $map2 === null && $map3 === null) {
                continue;
            }

            return [
                'dimensions' => $dimensions,
                'encodings'  => $encodings,
                'map1'       => $map1,
                'map2'       => $map2,
                'map3'       => $map3,
            ];
        }

        return null;
    }

    /**
     * Returns the optional profile look table definition.
     *
     * @return array{dimensions:list<int>|null,data:list<float>|null}|null
     */
    public function profileLookTable(): ?array
    {
        foreach ($this->profileIfds() as $ifd) {
            $dimensions = $this->numericList($ifd, ExifTag::PROFILE_LOOK_TABLE_DIMS);
            $data       = $this->rationalList($ifd, ExifTag::PROFILE_LOOK_TABLE_DATA);

            if ($dimensions === null && $data === null) {
                continue;
            }

            return [
                'dimensions' => $dimensions,
                'data'       => $data,
            ];
        }

        return null;
    }

    /**
     * Returns the optional profile tone curve points.
     *
     * @return list<float>|null
     */
    public function profileToneCurve(): ?array
    {
        foreach ($this->profileIfds() as $ifd) {
            $values = $this->rationalList($ifd, ExifTag::PROFILE_TONE_CURVE);
            if ($values === null) {
                continue;
            }

            if ($values === []) {
                continue;
            }

            return $values;
        }

        return null;
    }

    /**
     * Returns the optional profile gain table map payload.
     *
     * @return list<float>|null
     */
    public function profileGainTableMap(): ?array
    {
        $gainTableTag = DngProfileGainTableTag::GAIN_TABLE_MAP;

        foreach ($this->profileIfds() as $ifd) {
            $values = $this->rationalList($ifd, $gainTableTag->value);
            if ($values === null) {
                continue;
            }

            if ($values === []) {
                continue;
            }

            return $values;
        }

        return null;
    }

    /**
     * Returns the noise measurement recorded by the camera.
     */
    public function noise(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::NOISE);
    }

    /**
     * Returns the composite image classification when available.
     */
    public function compositeImage(): ?CompositeImage
    {
        $value = $this->int($this->exifIfd, ExifTag::COMPOSITE_IMAGE);

        return $value !== null ? CompositeImage::fromExifValue($value) : null;
    }

    /**
     * Returns the number of source images contributing to the composite result.
     *
     * @return array{0:int,1:int}|null
     */
    public function sourceImageNumberOfCompositeImage(): ?array
    {
        $values = $this->numericList($this->exifIfd, ExifTag::SOURCE_IMAGE_NUMBER_OF_COMPOSITE_IMAGE);

        if ($values === null || count($values) !== 2) {
            return null;
        }

        return [$values[0], $values[1]];
    }

    /**
     * Returns the exposure times for each source image used in the composite.
     *
     * @return list<float>|null
     */
    public function sourceExposureTimesOfCompositeImage(): ?array
    {
        $values = $this->rationalList($this->exifIfd, ExifTag::SOURCE_EXPOSURE_TIMES_OF_COMPOSITE_IMAGE);

        if ($values === null || $values === []) {
            return null;
        }

        return $values;
    }

    /**
     * Returns the CFA repeat pattern dimensions when valid.
     *
     * @return array{width:int, height:int}|null
     */
    public function cfaRepeatPatternDim(): ?array
    {
        $values = $this->numericList($this->exifIfd, ExifTag::CFA_REPEAT_PATTERN_DIM);

        if ($values === null || count($values) !== 2) {
            return null;
        }

        [$width, $height] = $values;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return [
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Returns the CFA pattern definition as a list of component identifiers.
     *
     * @return list<int>|null
     */
    public function cfaPattern(): ?array
    {
        return $this->numericList($this->exifIfd, ExifTag::CFA_PATTERN);
    }

    /**
     * Returns the CFA pattern as colour enums when possible.
     *
     * @return list<CfaPatternColor>|null
     */
    public function cfaPatternColors(): ?array
    {
        $pattern = $this->cfaPattern();

        return $pattern !== null ? ValueConverters::cfaPatternToColors($pattern) : null;
    }

    /**
     * Returns the scene type classification when present.
     */
    public function sceneType(): ?SceneType
    {
        $value = $this->value($this->exifIfd, ExifTag::SCENE_TYPE);

        if (is_int($value)) {
            return SceneType::fromExifValue($value);
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return is_int($first) ? SceneType::fromExifValue($first) : null;
        }

        if (is_string($value) && $value !== '') {
            return SceneType::fromExifValue(ord($value[0]));
        }

        return null;
    }

    /**
     * Returns whether a custom rendering process was applied.
     */
    public function customRendered(): ?CustomRendered
    {
        $value = $this->int($this->exifIfd, ExifTag::CUSTOM_RENDERED);

        return CustomRendered::fromExifValue($value);
    }

    /**
     * Returns the in-camera contrast setting.
     */
    public function contrast(): ?Contrast
    {
        $value = $this->int($this->exifIfd, ExifTag::CONTRAST);

        return Contrast::fromExifValue($value);
    }

    /**
     * Returns the in-camera saturation setting.
     */
    public function saturation(): ?Saturation
    {
        $value = $this->int($this->exifIfd, ExifTag::SATURATION);

        return Saturation::fromExifValue($value);
    }

    /**
     * Returns the in-camera sharpness setting.
     */
    public function sharpness(): ?Sharpness
    {
        $value = $this->int($this->exifIfd, ExifTag::SHARPNESS);

        return Sharpness::fromExifValue($value);
    }

    /**
     * Returns the device setting description payload.
     */
    public function deviceSettingDescription(): ?string
    {
        return $this->binaryString($this->exifIfd, ExifTag::DEVICE_SETTING_DESCRIPTION);
    }

    /**
     * Returns the self timer mode in seconds when available.
     */
    public function selfTimerModeSeconds(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::SELF_TIMER_MODE);
    }

    /**
     * Returns the camera battery level as a percentage when provided.
     */
    public function batteryLevelPercent(): ?float
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::BATTERY_LEVEL);

        return ValueConverters::batteryLevelToPercent($value);
    }

    /**
     * Returns the recorded temperature in Celsius.
     */
    public function temperatureCelsius(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::TEMPERATURE);
    }

    /**
     * Returns the relative humidity in percent.
     */
    public function humidityPercent(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::HUMIDITY);
    }

    /**
     * Returns the ambient pressure in hPa.
     */
    public function pressureHPa(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::PRESSURE);
    }

    /**
     * Returns the recorded water depth in metres.
     */
    public function waterDepthMeters(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::WATER_DEPTH);
    }

    /**
     * Returns the camera acceleration vector in metres per second squared.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function accelerationVector(): ?array
    {
        $value = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if (!$value instanceof ExifRationalList) {
            return null;
        }

        return ValueConverters::srationalTripletToFloatVector($value);
    }

    /**
     * Returns the camera acceleration in metres per second squared.
     */
    public function accelerationMs2(): ?float
    {
        $value = $this->valueFromGpsOrExif(ExifTag::ACCELERATION);

        if ($value instanceof ExifRationalList) {
            $vector = ValueConverters::srationalTripletToFloatVector($value);
            if ($vector === null) {
                return null;
            }

            return sqrt(($vector[0] ** 2) + ($vector[1] ** 2) + ($vector[2] ** 2));
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Returns the camera elevation angle in degrees.
     */
    public function cameraElevationAngleDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::CAMERA_ELEVATION_ANGLE);
    }

    /**
     * Returns the camera yaw in degrees.
     */
    public function cameraYawDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::CAMERA_YAW_DEGREE);
    }

    /**
     * Returns the camera pitch in degrees.
     */
    public function cameraPitchDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::CAMERA_PITCH_DEGREE);
    }

    /**
     * Returns the camera roll in degrees.
     */
    public function cameraRollDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::CAMERA_ROLL_DEGREE);
    }

    /**
     * Returns the aircraft flight yaw in degrees.
     */
    public function flightYawDeg(): ?float
    {
        return $this->cameraYawDeg();
    }

    /**
     * Returns the aircraft flight pitch in degrees.
     */
    public function flightPitchDeg(): ?float
    {
        return $this->cameraPitchDeg();
    }

    /**
     * Returns the aircraft flight roll in degrees.
     */
    public function flightRollDeg(): ?float
    {
        return $this->cameraRollDeg();
    }

    /**
     * Returns the gimbal yaw in degrees.
     */
    public function gimbalYawDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::GIMBAL_YAW_DEGREE);
    }

    /**
     * Returns the gimbal pitch in degrees.
     */
    public function gimbalPitchDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::GIMBAL_PITCH_DEGREE);
    }

    /**
     * Returns the gimbal roll in degrees.
     */
    public function gimbalRollDeg(): ?float
    {
        return $this->rationalFromGpsOrExif(ExifTag::GIMBAL_ROLL_DEGREE);
    }

    /**
     * Returns the camera firmware string when present.
     */
    public function cameraFirmware(): ?string
    {
        if ($this->exifThreeOrNewer) {
            $value = $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE);

            if ($value !== null) {
                return $value;
            }
        }

        return $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE_LEGACY);
    }

    /**
     * Returns the camera firmware version string when legacy tags are provided.
     *
     * EXIF 3.0 no longer defines a dedicated firmware version identifier, so
     * this method only returns data from the legacy tags preserved for
     * compatibility.
     */
    public function cameraFirmwareVersion(): ?string
    {
        if ($this->exifThreeOrNewer) {
            return null;
        }

        return $this->str($this->exifIfd, ExifTag::CAMERA_FIRMWARE_VERSION_LEGACY);
    }

    /**
     * Returns the raw developing software string.
     */
    public function rawDevelopingSoftware(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE);

        return $value ?? $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the raw developing software version string when legacy tags are provided.
     *
     * EXIF 3.0 reassigned the identifier to CAMERA_FIRMWARE, so only legacy
     * metadata produces a value.
     */
    public function rawDevelopingSoftwareVersion(): ?string
    {
        if ($this->exifThreeOrNewer) {
            return null;
        }

        return $this->str($this->exifIfd, ExifTag::RAW_DEVELOPING_SOFTWARE_VERSION_LEGACY);
    }

    /**
     * Returns the image editing software string.
     */
    public function imageEditingSoftware(): ?string
    {
        if ($this->exifThreeOrNewer) {
            $value = $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE);

            if ($value !== null) {
                return $value;
            }
        }

        return $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the image editing software version string when legacy tags are provided.
     *
     * EXIF 3.0 reassigned the identifier to IMAGE_EDITING_SOFTWARE, so only legacy
     * metadata produces a value.
     */
    public function imageEditingSoftwareVersion(): ?string
    {
        if ($this->exifThreeOrNewer) {
            return null;
        }

        return $this->str($this->exifIfd, ExifTag::IMAGE_EDITING_SOFTWARE_VERSION_LEGACY);
    }

    /**
     * Returns the metadata editing software string.
     */
    public function metadataEditingSoftware(): ?string
    {
        if ($this->exifThreeOrNewer) {
            $value = $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE);

            if ($value !== null) {
                return $value;
            }
        }

        return $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE_LEGACY);
    }

    /**
     * Returns the metadata editing software version string when legacy tags are provided.
     *
     * EXIF 3.0 reassigned the identifier to METADATA_EDITING_SOFTWARE, so only
     * legacy metadata produces a value.
     */
    public function metadataEditingSoftwareVersion(): ?string
    {
        if ($this->exifThreeOrNewer) {
            return null;
        }

        return $this->str($this->exifIfd, ExifTag::METADATA_EDITING_SOFTWARE_VERSION_LEGACY);
    }

    /**
     * Returns the raw DateTimeOriginal tag value.
     *
     * @return string|null
     */
    public function dateTimeOriginalRaw(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::DATETIME_ORIGINAL);
        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->str($ifd, ExifTag::DATETIME_ORIGINAL);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the DateTimeOriginal tag combined with fractional seconds and offsets when available.
     */
    public function dateTimeOriginal(): ?DateTimeImmutable
    {
        $dateTime = $this->parseExifDateTime(
            $this->dateTimeOriginalRaw(),
            $this->offsetTimeOriginal(),
            $this->subSecTimeOriginal(),
        );

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }

        $digitized = $this->dateTimeDigitized();
        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
    }

    /**
     * Returns the most appropriate capture timestamp prioritising DateTimeOriginal metadata.
     */
    public function dateTimeOriginalBestEffort(): ?DateTimeImmutable
    {
        $original = $this->dateTimeOriginal();
        if ($original instanceof DateTimeImmutable) {
            return $original;
        }

        $digitized = $this->dateTimeDigitized();
        if ($digitized instanceof DateTimeImmutable) {
            return $digitized;
        }

        return $this->captureDateTime();
    }

    /**
     * Returns the fractional seconds associated with DateTimeOriginal.
     */
    public function subSecTimeOriginal(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_ORIGINAL);
    }

    /**
     * Returns the raw DateTimeDigitized tag value.
     *
     * @return string|null
     */
    public function dateTimeDigitizedRaw(): ?string
    {
        $value = $this->str($this->exifIfd, ExifTag::DATETIME_DIGITIZED);
        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->str($ifd, ExifTag::DATETIME_DIGITIZED);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the fractional seconds for DateTimeDigitized.
     */
    public function subSecTimeDigitized(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME_DIGITIZED);
    }

    /**
     * Returns the raw ModifyDate (legacy DateTime) tag value from IFD0.
     *
     * @return string|null
     */
    public function dateTimeRaw(): ?string
    {
        return $this->str($this->ifd0, ExifTag::DATETIME);
    }

    /**
     * Returns the fractional seconds for the ModifyDate/DateTime tag.
     */
    public function subSecTime(): ?string
    {
        return $this->sanitizedSubSec($this->exifIfd, ExifTag::SUB_SEC_TIME);
    }

    /**
     * Returns the EXIF time zone offset values expressed in minutes from UTC.
     *
     * @return list<int>|null
     */
    public function timeZoneOffsetMinutes(): ?array
    {
        $value = $this->value($this->exifIfd, ExifTag::TIME_ZONE_OFFSET);

        if ($value === null) {
            return null;
        }

        if ($value instanceof ExifRationalList) {
            if ($value->values === []) {
                return null;
            }

            $minutes = [];

            foreach ($value->values as $component) {
                $converted = ValueConverters::offsetToMinutes($component);
                if ($converted === null) {
                    return null;
                }

                $minutes[] = $converted;
            }

            return $minutes;
        }

        if ($value instanceof ExifNumericList) {
            if ($value->values === []) {
                return null;
            }

            $minutes = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $component = $component->toInt('EXIF numeric list component');
                }

                $converted = ValueConverters::offsetToMinutes($component);
                if ($converted === null) {
                    return null;
                }

                $minutes[] = $converted;
            }

            return $minutes;
        }

        if ($value instanceof ExifRational) {
            $converted = ValueConverters::offsetToMinutes($value);

            return $converted === null ? null : [$converted];
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $converted = ValueConverters::offsetToMinutes($value);

            return $converted === null ? null : [$converted];
        }

        return null;
    }

    /**
     * Returns the normalized offset time for DateTimeOriginal.
     */
    public function offsetTimeOriginal(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME_ORIGINAL);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the normalized offset time for DateTimeDigitized.
     */
    public function offsetTimeDigitized(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME_DIGITIZED);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the normalized offset time for the IFD0 ModifyDate/DateTime tag.
     */
    public function offsetTime(): ?string
    {
        $offset = $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME);
        if ($offset !== null) {
            return $offset;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->normalizedOffset($ifd, ExifTag::OFFSET_TIME);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the raw offset time for DateTimeOriginal.
     *
     * @return string|null
     */
    public function offsetTimeOriginalRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the raw offset time for DateTimeDigitized.
     *
     * @return string|null
     */
    public function offsetTimeDigitizedRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the raw offset time for the ModifyDate/DateTime tag.
     *
     * @return string|null
     */
    public function offsetTimeRaw(): ?string
    {
        return $this->str($this->exifIfd, ExifTag::OFFSET_TIME);
    }

    /**
     * Returns the parsed GPS metadata extracted from the GPS IFD.
     *
     * @return array{
     *     lat_ref:?string,
     *     lat:?float,
     *     lon_ref:?string,
     *     lon:?float,
     *     alt_ref:?int,
     *     alt:?float,
     *     version:?string,
     *     satellites:?string,
     *     status:?string,
     *     measure_mode:?string,
     *     dop:?float,
     *     speed_ref:?string,
     *     speed_ms:?float,
     *     track_ref:?string,
     *     track:?float,
     *     img_direction_ref:?string,
     *     img_direction:?float,
     *     map_datum:?string,
     *     dest_lat_ref:?string,
     *     dest_lat:?float,
     *     dest_lon_ref:?string,
     *     dest_lon:?float,
     *     dest_bearing_ref:?string,
     *     dest_bearing:?float,
     *     dest_distance_ref:?string,
     *     dest_distance_m:?float,
     *     processing_method:?string,
     *     area_information:?string,
     *     date:?string,
     *     time:?string,
     *     timestamp:?DateTimeImmutable,
     *     differential:?int,
     *     h_positioning_error:?float
     * }
     */
    public function gps(): array
    {
        if (!$this->gpsIfd instanceof Ifd) {
            return ValueConverters::emptyGpsResult();
        }

        return ValueConverters::gpsFromIfd($this->gpsIfd);
    }

    /**
     * Returns the recorded GPS date stamp in ISO calendar format.
     */
    public function gpsDateStamp(): ?string
    {
        $value = $this->gpsValue('date');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the recorded GPS time stamp in HH:MM:SS(.sss) format.
     */
    public function gpsTimeStampString(): ?string
    {
        $value = $this->gpsValue('time');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the combined GPS timestamp in UTC when both date and time are available.
     */
    public function gpsTimestamp(): ?DateTimeImmutable
    {
        $value = $this->gpsValue('timestamp');

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Returns the GPSSpeedRef value indicating the source units (K, M, N).
     */
    public function gpsSpeedRef(): ?string
    {
        $value = $this->gpsValue('speed_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the GPS speed converted to metres per second.
     */
    public function gpsSpeedMetresPerSecond(): ?float
    {
        $value = $this->gpsValue('speed_ms');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPSTrackRef value (T for true, M for magnetic).
     */
    public function gpsTrackRef(): ?string
    {
        $value = $this->gpsValue('track_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised course over ground in degrees within [0, 360).
     */
    public function gpsTrack(): ?float
    {
        $value = $this->gpsValue('track');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPSImgDirectionRef value.
     */
    public function gpsImgDirectionRef(): ?string
    {
        $value = $this->gpsValue('img_direction_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised image direction in degrees within [0, 360).
     */
    public function gpsImgDirection(): ?float
    {
        $value = $this->gpsValue('img_direction');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPSDestBearingRef value (true or magnetic).
     */
    public function gpsDestinationBearingRef(): ?string
    {
        $value = $this->gpsValue('dest_bearing_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the normalised destination bearing in degrees within [0, 360).
     */
    public function gpsDestinationBearing(): ?float
    {
        $value = $this->gpsValue('dest_bearing');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPSDestDistanceRef value (kilometres, miles or nautical miles).
     */
    public function gpsDestinationDistanceRef(): ?string
    {
        $value = $this->gpsValue('dest_distance_ref');

        return is_string($value) ? $value : null;
    }

    /**
     * Returns the destination distance converted to metres.
     */
    public function gpsDestinationDistanceMetres(): ?float
    {
        $value = $this->gpsValue('dest_distance_m');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns the GPS differential correction indicator.
     */
    public function gpsDifferential(): ?int
    {
        $value = $this->gpsValue('differential');

        return is_int($value) ? $value : null;
    }

    /**
     * Returns the horizontal positioning error in metres when provided.
     */
    public function gpsHorizontalPositioningError(): ?float
    {
        $value = $this->gpsValue('h_positioning_error');

        return is_float($value) ? $value : null;
    }

    /**
     * Returns a single value from the cached GPS metadata map.
     *
     * @param string $key
     *
     * @return string|int|float|DateTimeImmutable|null
     */
    private function gpsValue(string $key): string|int|float|DateTimeImmutable|null
    {
        $gps = $this->gps();

        return $gps[$key] ?? null;
    }

    /**
     * Returns a best-effort capture timestamp. Defaults to UTC when no offset tag is provided.
     *
     * @return DateTimeImmutable|null
     */
    public function captureDateTime(): ?DateTimeImmutable
    {
        $offsetOriginal  = $this->offsetTimeOriginal();
        $offsetDigitized = $this->offsetTimeDigitized();
        $offset          = $this->offsetTime();

        $fallbackOriginal  = null;
        $fallbackDigitized = null;
        $fallbackModify    = null;

        if ($offsetOriginal === null && $offsetDigitized === null && $offset === null) {
            $primaryOffset   = $this->derivedOffsetFromTimeZoneOffset();
            $secondaryOffset = $this->derivedOffsetFromTimeZoneOffset(1);

            $fallbackOriginal  = $primaryOffset;
            $fallbackDigitized = $secondaryOffset ?? $primaryOffset;
            $fallbackModify    = $primaryOffset;
        }

        $attempts = [
            [$this->dateTimeOriginalRaw(), $offsetOriginal ?? $fallbackOriginal, $this->subSecTimeOriginal()],
            [$this->dateTimeDigitizedRaw(), $offsetDigitized ?? $fallbackDigitized, $this->subSecTimeDigitized()],
            [$this->dateTimeRaw(), $offset ?? $fallbackModify, $this->subSecTime()],
        ];

        foreach ($attempts as [$raw, $rawOffset, $subSeconds]) {
            $dateTime = $this->parseExifDateTime($raw, $rawOffset, $subSeconds);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        return $this->gpsTimestamp();
    }

    /**
     * Returns the digitised timestamp combining the raw value and offset tags.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTimeDigitized(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeDigitizedRaw(),
            $this->offsetTimeDigitized(),
            $this->subSecTimeDigitized(),
        );
    }

    /**
     * Returns the ModifyDate/DateTime tag combined with its optional offset.
     *
     * @return DateTimeImmutable|null
     */
    public function dateTime(): ?DateTimeImmutable
    {
        return $this->parseExifDateTime(
            $this->dateTimeRaw(),
            $this->offsetTime(),
            $this->subSecTime(),
        );
    }

    /**
     * Returns IFDs that should be used as profile computation sources.
     *
     * @return list<Ifd> List of source IFDs in priority order.
     */
    private function profileSourceIfds(): array
    {
        $sources = [];

        foreach ([$this->exifIfd, $this->ifd0, $this->ifd1] as $ifd) {
            if ($ifd instanceof Ifd) {
                $sources[] = $ifd;
            }
        }

        foreach ($this->subsequentIfds as $ifd) {
            $sources[] = $ifd;
        }

        foreach ($this->subIfds as $ifd) {
            $sources[] = $ifd;
        }

        return $sources;
    }

    /**
     * Returns IFDs containing profile-related metadata.
     *
     * @return list<Ifd> List of IFDs with profile data.
     */
    private function profileIfds(): array
    {
        $candidates = [];

        $gainTableTag = DngProfileGainTableTag::GAIN_TABLE_MAP;

        foreach ($this->profileSourceIfds() as $ifd) {
            if ($ifd->get(ExifTag::PROFILE_HUE_SAT_MAP_DIMS) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_HUE_SAT_MAP_ENCODINGS) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_LOOK_TABLE_DIMS) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_TONE_CURVE) instanceof IfdEntry
                || $ifd->get($gainTableTag->value) instanceof IfdEntry) {
                $candidates[] = $ifd;
            }
        }

        return $candidates;
    }

    /**
     * Returns the artist tag value when present.
     */
    public function artist(): ?string
    {
        return $this->str($this->ifd0, ExifTag::ARTIST);
    }

    /**
     * Returns the bits per sample defined for the primary image.
     */
    public function bitsPerSample(): ?int
    {
        return $this->int($this->ifd0, ExifTag::BITS_PER_SAMPLE);
    }

    /**
     * Returns the number of samples per pixel when provided by the TIFF data.
     */
    public function samplesPerPixel(): ?int
    {
        return $this->int($this->ifd0, ExifTag::SAMPLES_PER_PIXEL);
    }

    /**
     * Returns the rows per strip value when the image data is organized in strips.
     */
    public function rowsPerStrip(): ?int
    {
        return $this->int($this->ifd0, ExifTag::ROWS_PER_STRIP);
    }

    /**
     * Returns the TIFF compression method enum.
     */
    public function compression(): ?Compression
    {
        $value = $this->enumValue($this->ifd0, ExifTag::COMPRESSION);

        return Compression::fromExifValue($value);
    }

    /**
     * Returns the photometric interpretation enum.
     */
    public function photometric(): ?Photometric
    {
        $value = $this->enumValue($this->ifd0, ExifTag::PHOTOMETRIC_INTERPRETATION);

        return Photometric::fromExifValue($value);
    }

    /**
     * Returns the planar configuration enum when recorded.
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        $value = $this->enumValue($this->ifd0, ExifTag::PLANAR_CONFIGURATION);

        return PlanarConfiguration::fromExifValue($value);
    }

    /**
     * Returns the resolution unit enum for the reported X/Y resolution values.
     */
    public function resolutionUnit(): ?ResolutionUnit
    {
        $value = $this->enumValue($this->ifd0, ExifTag::RESOLUTION_UNIT);

        return ResolutionUnit::fromExifValue($value);
    }

    /**
     * Returns the horizontal resolution value expressed in the resolution unit.
     */
    public function xResolution(): ?float
    {
        return $this->rational($this->ifd0, ExifTag::X_RESOLUTION);
    }

    /**
     * Returns the vertical resolution value expressed in the resolution unit.
     */
    public function yResolution(): ?float
    {
        return $this->rational($this->ifd0, ExifTag::Y_RESOLUTION);
    }

    /**
     * Returns the YCbCr positioning enum describing the chroma siting.
     */
    public function ycbcrPositioning(): ?YCbCrPositioning
    {
        $value = $this->enumValue($this->ifd0, ExifTag::YCBCR_POSITIONING);

        return YCbCrPositioning::fromExifValue($value);
    }

    /**
     * Returns the YCbCr subsampling factors.
     *
     * @return array{0:int,1:int}|null
     */
    public function ycbcrSubSampling(): ?array
    {
        $values = $this->numericList($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        if ($values !== null) {
            if (count($values) === 2) {
                return [$values[0], $values[1]];
            }

            return null;
        }

        $raw = $this->rawString($this->ifd0, ExifTag::YCBCR_SUB_SAMPLING);

        return $raw !== null ? ValueConverters::ycbcrSubSamplingToPair($raw) : null;
    }

    /**
     * Returns the YCbCr conversion coefficients when provided.
     *
     * @return array{0:float,1:float,2:float}|null
     */
    public function ycbcrCoefficients(): ?array
    {
        $value = $this->normalisedValue($this->ifd0, ExifTag::YCBCR_COEFFICIENTS);

        if ($value instanceof ExifNumericList) {
            $coeffs = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $coeffs[] = (float) $component->toInt('YCbCr coefficient component');

                    continue;
                }

                $coeffs[] = (float) $component;
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        if ($value instanceof ExifRationalList) {
            $coeffs = [];
            foreach ($value->values as $component) {
                $float = ValueConverters::rationalToFloat($component);
                if ($float === null) {
                    return null;
                }

                $coeffs[] = $float;
            }

            return count($coeffs) === 3 ? $coeffs : null;
        }

        return null;
    }

    /**
     * Returns the normalized white point coordinates.
     *
     * @return array{0:float,1:float}|null
     */
    public function whitePoint(): ?array
    {
        $value = $this->value($this->ifd0, ExifTag::WHITE_POINT);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? ValueConverters::toWhitePoint($value)
            : null;
    }

    /**
     * Returns the primary chromaticities ordered as R,G,B.
     *
     * @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null
     */
    public function primaryChromaticities(): ?array
    {
        $value = $this->value($this->ifd0, ExifTag::PRIMARY_CHROMATICITIES);

        return $value instanceof ExifRationalList || $value instanceof ExifNumericList
            ? ValueConverters::toPrimaryChromaticities($value)
            : null;
    }

    /**
     * Returns the TIFF predictor value for differencing compression schemes.
     *
     * TIFF 6.0 §14 defines the Predictor tag as a mathematical operator applied before
     * compression. Valid values: 1 = No prediction (default), 2 = Horizontal differencing.
     */
    public function predictor(): ?int
    {
        return $this->int($this->ifd0, ExifTag::PREDICTOR);
    }

    /**
     * Returns the embedded ICC color profile binary data when present.
     *
     * TIFF 6.0 §20 and ICC.1:2001-04 specify the ICC profile tag (0x8773) as containing
     * the raw ICC profile binary stream for color-managed workflows.
     */
    public function iccProfile(): ?string
    {
        return $this->rawString($this->ifd0, ExifTag::ICC_PROFILE);
    }

    /**
     * Returns the JPEG interchange format offset for legacy thumbnails.
     */
    public function jpegInterchangeFormat(): ?int
    {
        return $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG interchange format length for legacy thumbnails.
     */
    public function jpegInterchangeFormatLength(): ?int
    {
        return $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Returns the interoperability index string when recorded.
     */
    public function interopIndex(): ?string
    {
        return $this->str($this->interopIfd, ExifTag::INTEROPERABILITY_INDEX);
    }

    /**
     * Returns the gamma correction value when provided.
     */
    public function gamma(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::GAMMA);
    }

    /**
     * Returns the digital zoom ratio when encoded by the camera.
     */
    public function digitalZoomRatio(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::DIGITAL_ZOOM_RATIO);
    }

    /**
     * Returns the exposure mode enum indicating manual or auto settings.
     */
    public function exposureMode(): ?ExposureMode
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::EXPOSURE_MODE);

        return ExposureMode::fromExifValue($value);
    }

    /**
     * Returns the gain control enum describing in-camera amplification.
     */
    public function gainControl(): ?GainControl
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::GAIN_CONTROL);

        return GainControl::fromExifValue($value);
    }

    /**
     * Returns the EXIF file source enum when provided.
     */
    public function fileSource(): ?FileSource
    {
        $value = $this->value($this->exifIfd, ExifTag::FILE_SOURCE);

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if (is_int($first) || is_float($first)) {
                return FileSource::fromExifValue((int) $first);
            }

            return null;
        }

        if (is_int($value) || is_float($value)) {
            return FileSource::fromExifValue((int) $value);
        }

        if (is_string($value) && $value !== '') {
            return FileSource::fromExifValue(ord($value[0]));
        }

        return null;
    }

    /**
     * Returns the EXIF sensing method enum when provided.
     */
    public function sensingMethod(): ?SensingMethod
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::SENSING_METHOD);

        return SensingMethod::fromExifValue($value);
    }

    /**
     * Returns the light source enum describing the scene illumination.
     *
     * @return LightSource|null
     */
    public function lightSource(): ?LightSource
    {
        $rawLightSource = $this->enumValue($this->exifIfd, ExifTag::LIGHT_SOURCE);

        // The enum helper accepts integers as well as numeric strings.
        return LightSource::fromExifValue($rawLightSource);
    }

    /**
     * Returns the scene capture type enum when recorded.
     *
     * @return SceneCaptureType|null
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        $rawSceneCaptureType = $this->enumValue($this->exifIfd, ExifTag::SCENE_CAPTURE_TYPE);

        return SceneCaptureType::fromExifValue($rawSceneCaptureType);
    }

    /**
     * Returns the subject distance range enum when provided.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        $value = $this->enumValue($this->exifIfd, ExifTag::SUBJECT_DISTANCE_RANGE);

        return SubjectDistanceRange::fromExifValue($value);
    }

    /**
     * Returns the ISO latitude yyy value when available.
     */
    public function isoLatitudeYyy(): ?int
    {
        return $this->isoSpeedLatitudeYyy();
    }

    /**
     * Returns the ISO latitude zzz value when available.
     */
    public function isoLatitudeZzz(): ?int
    {
        return $this->isoSpeedLatitudeZzz();
    }

    /**
     * Returns the shutter speed APEX value.
     */
    public function shutterSpeedEv(): ?float
    {
        return $this->shutterSpeedValue();
    }

    /**
     * Returns the aperture APEX value.
     */
    public function apertureEv(): ?float
    {
        return $this->apertureValue();
    }

    /**
     * Returns the subject distance in metres when provided.
     */
    public function subjectDistance(): ?float
    {
        return $this->rational($this->exifIfd, ExifTag::SUBJECT_DISTANCE);
    }

    /**
     * Returns the EXIF subject area values as integers.
     *
     * @return list<int>|null
     */
    public function subjectArea(): ?array
    {
        $value = $this->normalisedValue($this->exifIfd, ExifTag::SUBJECT_AREA);

        if ($value instanceof ExifNumericList) {
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $components[] = $component->toInt('EXIF subject area component');

                    continue;
                }

                $components[] = (int) $component;
            }

            return $components;
        }

        if (is_int($value) || is_float($value)) {
            return [(int) $value];
        }

        return null;
    }

    /**
     * Returns the CFA repeat pattern width in samples.
     */
    public function cfaRepeatPatternWidth(): ?int
    {
        $dims = $this->cfaRepeatPatternDim();

        return $dims['width'] ?? null;
    }

    /**
     * Returns the CFA repeat pattern height in samples.
     */
    public function cfaRepeatPatternHeight(): ?int
    {
        $dims = $this->cfaRepeatPatternDim();

        return $dims['height'] ?? null;
    }

    /**
     * Returns the noise reduction strength encoded by the camera.
     */
    public function noiseReduction(): ?float
    {
        return $this->noise();
    }

    private function str(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->normalisedValue($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim($value, "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        $value = $this->normalisedValue($ifd, $tag);

        return $this->coerceIntValue($value);
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return float|null
     */
    private function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->normalisedValue($ifd, $tag);

        if ($value === null) {
            return null;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Returns a rational or numeric entry converted to float, preferring GPS data when available.
     */
    private function rationalFromGpsOrExif(int $tag): ?float
    {
        $value = $this->valueFromGpsOrExif($tag);

        if ($value === null) {
            return null;
        }

        return ValueConverters::rationalToFloat($value);
    }

    /**
     * Retrieves a raw entry value preferring the GPS IFD before falling back to the EXIF IFD.
     *
     * @param int $tag
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    private function valueFromGpsOrExif(int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $value = $this->normalisedValue($this->gpsIfd, $tag);

        return $value ?? $this->normalisedValue(
            $this->exifIfd,
            $tag
        );
    }

    /**
     * Retrieves the raw entry value for the provided tag.
     *
     * @param Ifd|null $ifd
     * @param int      $tag
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
     */
    private function value(?Ifd $ifd, int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);

        return $entry?->value;
    }

    private function normalisedValue(
        ?Ifd $ifd,
        int $tag,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        $value = $this->value($ifd, $tag);

        return $this->normaliseScalarValue($value);
    }

    private function normaliseScalarValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null {
        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF scalar normalisation');
        }

        if ($value instanceof ExifNumericList) {
            $normalised = [];
            $changed    = false;

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $normalised[] = $component->toInt('EXIF numeric list normalisation');
                    $changed      = true;

                    continue;
                }

                $normalised[] = $component;
            }

            if ($changed) {
                return new ExifNumericList($normalised);
            }

            return $value;
        }

        return $value;
    }

    private function enumValue(?Ifd $ifd, int $tag): int|string|null
    {
        $value = $this->value($ifd, $tag);

        return $this->normaliseEnumScalar($value);
    }

    private function normaliseEnumScalar(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): int|string|null {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $this->normaliseEnumScalar($first);
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : $this->normaliseEnumScalar($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF enum value normalisation');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Extracts components configuration input from IFD.
     *
     * @param Ifd|null $ifd IFD to search.
     * @param int      $tag Tag number to retrieve.
     *
     * @return array<int, int|float|string>|int|string|null Components input value or null if not found.
     */
    private function componentsInput(?Ifd $ifd, int $tag): array|int|string|null
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $components = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $components[] = $component->toInt('EXIF components configuration');

                    continue;
                }

                $components[] = $component;
            }

            return $components;
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            if (!$first instanceof ExifRational) {
                return null;
            }

            $float = ValueConverters::rationalToFloat($first);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : $this->componentsInputFromScalar($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF components configuration');
        }

        return $this->componentsInputFromScalar($value);
    }

    /**
     * Converts a scalar components configuration value to normalized form.
     *
     * @param int|float|string|null $value Scalar value to normalize.
     *
     * @return int|string|null Normalized component value or null.
     */
    private function componentsInputFromScalar(int|float|string|null $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * Provides the fallback IFDs consulted when primary metadata is absent.
     *
     * @param bool $includePrimaryThumbnail When true the primary thumbnail (IFD1) is considered.
     * @param bool $includeIfd0             When true the root directory (IFD0) is appended as a last resort.
     *
     * @return list<Ifd>
     */
    private function fallbackIfds(bool $includePrimaryThumbnail = true, bool $includeIfd0 = false): array
    {
        $ifds = [];
        $seen = [];

        $append = static function (?Ifd $candidate) use (&$ifds, &$seen): void {
            if (!$candidate instanceof Ifd) {
                return;
            }

            $id = spl_object_id($candidate);
            if (isset($seen[$id])) {
                return;
            }

            $seen[$id] = true;
            $ifds[]    = $candidate;
        };

        if ($includePrimaryThumbnail) {
            $append($this->ifd1);
        }

        foreach ($this->subIfds as $ifd) {
            $append($ifd);
        }

        foreach ($this->subsequentIfds as $ifd) {
            $append($ifd);
        }

        if ($includeIfd0) {
            $append($this->ifd0);
        }

        return $ifds;
    }

    /**
     * Coerces a raw EXIF scalar value into an integer when possible.
     */
    private function coerceIntValue(
        int|float|string|ExifRational|ExifRationalList|ExifNumericList|UInt64|null $value,
    ): ?int {
        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            return $this->coerceIntValue($first);
        }

        if ($value instanceof ExifRationalList) {
            $first = $value->values[0] ?? null;

            return $first instanceof ExifRational ? $this->coerceIntValue($first) : null;
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float === null ? null : (int) round($float);
        }

        if ($value instanceof UInt64) {
            if (!$value->fitsSignedInt()) {
                return null;
            }

            return $value->toInt('EXIF integer coercion');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) round((float) $trimmed);
            }

            if (preg_match('/\d+/', $trimmed, $matches) === 1) {
                return (int) $matches[0];
            }

            return null;
        }

        return null;
    }

    /**
     * Returns the raw string value without trimming trailing null bytes.
     */
    private function rawString(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        return is_string($value) ? $value : null;
    }

    /**
     * Retrieves the raw user comment value from primary and fallback directories.
     */
    private function rawUserComment(): ?string
    {
        $raw = $this->rawString($this->exifIfd, ExifTag::USER_COMMENT);
        if ($raw !== null) {
            return $raw;
        }

        foreach ($this->fallbackIfds(includeIfd0: true) as $ifd) {
            $candidate = $this->rawString($ifd, ExifTag::USER_COMMENT);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Converts a numeric list or undefined string into a list of integers.
     *
     * @return list<int>|null
     */
    private function numericList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            return array_map(
                static function (int|float|UInt64 $component): int {
                    if ($component instanceof UInt64) {
                        return $component->toInt('EXIF numeric list component');
                    }

                    return (int) $component;
                },
                $value->values,
            );
        }

        if (is_int($value)) {
            return [$value];
        }

        if (is_string($value) && $value !== '') {
            $length = strlen($value);
            $bytes  = [];
            for ($i = 0; $i < $length; ++$i) {
                $bytes[] = ord($value[$i]);
            }

            return $bytes;
        }

        return null;
    }

    /**
     * Converts rational or numeric list values into floating point lists.
     *
     * @return list<float>|null
     */
    private function rationalList(?Ifd $ifd, int $tag): ?array
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifRationalList) {
            $result = [];
            foreach ($value->values as $item) {
                $float = ValueConverters::rationalToFloat($item);
                if ($float === null) {
                    return null;
                }

                $result[] = $float;
            }

            return $result;
        }

        if ($value instanceof ExifRational) {
            $float = ValueConverters::rationalToFloat($value);

            return $float !== null ? [$float] : null;
        }

        if ($value instanceof ExifNumericList) {
            $floats = [];

            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    if (!$component->fitsSignedInt()) {
                        return null;
                    }

                    $floats[] = (float) $component->toInt('EXIF rational list component');

                    continue;
                }

                $floats[] = (float) $component;
            }

            return $floats;
        }

        if (is_int($value) || is_float($value)) {
            return [(float) $value];
        }

        return null;
    }

    /**
     * Returns a trimmed binary string value, or null when empty.
     */
    private function binaryString(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->rawString($ifd, $tag);
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value, "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decodes EXIF user comment strings with encoding prefixes.
     */
    private function decodeUserComment(string $raw): ?string
    {
        if (strlen($raw) <= 8) {
            $content = trim($raw, "\0");

            if ($content === '') {
                return null;
            }

            $encoding = $this->inferUserCommentEncoding($raw);
            if ($encoding === 'UNICODE') {
                return $this->decodeUnicodeComment($raw);
            }

            return $content;
        }

        $prefix            = substr($raw, 0, 8);
        $canonicalEncoding = $this->canonicalUserCommentMarker($prefix);
        $hasKnownPrefix    = $canonicalEncoding !== '';
        $content           = $hasKnownPrefix ? substr($raw, 8) : $raw;
        $sanitized         = trim($content, "\0 ");

        if ($hasKnownPrefix) {
            $resolvedEncoding = $sanitized === '' ? null : $canonicalEncoding;
        } else {
            $resolvedEncoding = $this->inferUserCommentEncoding($content);
        }

        if ($resolvedEncoding === null) {
            return null;
        }

        return match ($resolvedEncoding) {
            'UNICODE' => $this->decodeUnicodeComment($content),
            'JIS'     => $this->decodeJisComment($sanitized),
            default   => $sanitized === '' ? null : $sanitized,
        };
    }

    /**
     * Decodes a Shift-JIS encoded user comment.
     */
    private function decodeJisComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $sources = ['SJIS', 'SJIS-win', 'CP932'];
        $targets = ['UTF-8', 'UTF-8//IGNORE', 'UTF-8//TRANSLIT'];

        foreach ($sources as $source) {
            foreach ($targets as $target) {
                $converted = @iconv($source, $target, $content);
                if ($converted === false) {
                    continue;
                }

                $trimmed = trim($converted);
                if ($trimmed === '') {
                    continue;
                }

                return $trimmed;
            }
        }

        $stripped = preg_replace('/[^\x20-\x7E]/', '', $content);
        if ($stripped === null) {
            return null;
        }

        $trimmed = trim($stripped);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decodes a UTF-16 encoded user comment.
     */
    private function decodeUnicodeComment(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $converted = @iconv('UTF-16LE', 'UTF-8', $content);
        if ($converted === false) {
            $converted = @iconv('UTF-16BE', 'UTF-8', $content);
        }

        if ($converted !== false) {
            $converted = trim($converted, "\0");

            return $converted === '' ? null : $converted;
        }

        $stripped = preg_replace('/\x00/u', '', $content);
        if ($stripped === null) {
            return null;
        }

        $stripped = trim($stripped, "\0");

        return $stripped === '' ? null : $stripped;
    }

    /**
     * Infers the most likely user comment encoding based on the raw payload.
     */
    private function inferUserCommentEncoding(string $content): ?string
    {
        $trimmed = trim($content, "\0 ");
        if ($trimmed === '') {
            return null;
        }

        if ($this->looksLikeUtf16($content)) {
            return 'UNICODE';
        }

        if ($this->looksPrintableAscii($trimmed)) {
            return 'ASCII';
        }

        return 'UNDEFINED';
    }

    /**
     * Checks whether the payload is limited to printable ASCII characters.
     */
    private function looksPrintableAscii(string $content): bool
    {
        $length = strlen($content);
        for ($i = 0; $i < $length; ++$i) {
            $byte = ord($content[$i]);
            if ($byte < 0x20 && !in_array($byte, [0x09, 0x0A, 0x0D], true)) {
                return false;
            }

            if ($byte > 0x7E) {
                return false;
            }
        }

        return true;
    }

    /**
     * Heuristically determines whether the payload resembles UTF-16 text.
     */
    private function looksLikeUtf16(string $content): bool
    {
        $length = strlen($content);
        if ($length < 2) {
            return false;
        }

        $bom = substr($content, 0, 2);
        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            return true;
        }

        $nullCount = substr_count($content, "\x00");
        if ($nullCount < 2) {
            return false;
        }

        $sampleLength = min($length, 32);
        $sample       = substr($content, 0, $sampleLength);

        $nullsOnEven = 0;
        $nullsOnOdd  = 0;

        $sampleSize = strlen($sample);
        for ($i = 0; $i < $sampleSize; ++$i) {
            if ($sample[$i] === "\x00") {
                if (($i % 2) === 0) {
                    ++$nullsOnEven;
                } else {
                    ++$nullsOnOdd;
                }
            }
        }

        if ($nullsOnEven === 0 && $nullsOnOdd === 0) {
            return false;
        }

        if ($nullsOnEven === 0 || $nullsOnOdd === 0) {
            return true;
        }

        if ($nullCount <= 2) {
            return false;
        }

        return $nullCount >= (int) ($length / 4);
    }

    /**
     * Derives a canonical offset string from the legacy TimeZoneOffset tag when available.
     */
    private function derivedOffsetFromTimeZoneOffset(int $component = 0): ?string
    {
        $values = $this->timeZoneOffsetMinutes();

        if ($values === null || !array_key_exists($component, $values)) {
            return null;
        }

        $minutes          = $values[$component];
        $sign             = $minutes < 0 ? '-' : '+';
        $absolute         = abs($minutes);
        $hours            = intdiv($absolute, 60);
        $remainingMinutes = $absolute % 60;

        return sprintf('%s%02d:%02d', $sign, $hours, $remainingMinutes);
    }

    /**
     * Normalises textual and numeric offset encodings to a canonical string representation.
     */
    private function normalizedOffset(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $value = $value->values[0] ?? null;

            if ($value instanceof UInt64) {
                if (!$value->fitsSignedInt()) {
                    return null;
                }

                $value = $value->toInt('EXIF offset normalisation');
            }
        } elseif ($value instanceof ExifRationalList || $value instanceof ExifRational) {
            $value = ValueConverters::rationalToFloat($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $value = $trimmed;
        }

        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return ValueConverters::parseOffsetString($value);
    }

    /**
     * Returns sanitized sub-second components limited to microsecond precision.
     */
    private function sanitizedSubSec(?Ifd $ifd, int $tag): ?string
    {
        $value = $this->value($ifd, $tag);

        if (is_string($value)) {
            $digits = preg_replace('/[^0-9]/', '', $value);

            return ($digits !== null && $digits !== '') ? $digits : null;
        }

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;

            if ($first instanceof UInt64) {
                if (!$first->fitsSignedInt()) {
                    return null;
                }

                $first = $first->toInt('EXIF sub-second component');
            }

            if ($first === null) {
                return null;
            }

            return (string) (int) $first;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Normalises EXIF timestamp strings and optional offsets into immutable datetime instances.
     *
     * @param string|null $rawDateTime Raw EXIF datetime formatted as "YYYY:MM:DD HH:MM:SS".
     * @param string|null $rawOffset   Optional timezone offset such as "+01:00".
     */
    private function parseExifDateTime(?string $rawDateTime, ?string $rawOffset, ?string $subSeconds): ?DateTimeImmutable
    {
        if ($rawDateTime === null || $rawDateTime === '' || strlen($rawDateTime) < 19) {
            return null;
        }

        try {
            $tz = ($rawOffset !== null && $rawOffset !== '')
                ? new DateTimeZone($rawOffset)
                : new DateTimeZone('UTC');
        } catch (Exception) {
            return null;
        }

        $normalized = str_replace(':', '-', substr($rawDateTime, 0, 10)) . substr($rawDateTime, 10);
        $format     = 'Y-m-d H:i:s';

        if ($subSeconds !== null && $subSeconds !== '') {
            $digits = preg_replace('/[^0-9]/', '', $subSeconds);
            if ($digits !== null && $digits !== '') {
                $digits = substr($digits, 0, 6);
                $digits = str_pad($digits, 6, '0');
                $normalized .= '.' . $digits;
                $format .= '.u';
            }
        }

        $dt = DateTimeImmutable::createFromFormat($format, $normalized, $tz);

        return $dt !== false ? $dt : null;
    }
}
