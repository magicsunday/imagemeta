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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\ExposureMode;
use MagicSunday\ImageMeta\Value\Enum\FileSource;
use MagicSunday\ImageMeta\Value\Enum\GainControl;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\Photometric;
use MagicSunday\ImageMeta\Value\Enum\PlanarConfiguration;
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use MagicSunday\ImageMeta\Value\Enum\CompositeImage;
use MagicSunday\ImageMeta\Value\Enum\Contrast;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\Saturation;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\Sharpness;

use function abs;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function iconv;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sprintf;
use function sqrt;
use function str_pad;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function trim;
use function intdiv;

/**
 * Represents a parsed EXIF payload and exposes convenience accessors.
 */
final readonly class ExifDocument
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
     * @param Ifd                     $ifd0           Root IFD of the TIFF structure.
     * @param Ifd|null                $exifIfd        Sub IFD containing EXIF-specific tags.
     * @param Ifd|null                $gpsIfd         Sub IFD containing GPS-related tags.
     * @param Ifd|null                $interopIfd     Sub IFD containing interoperability tags.
     * @param Ifd|null                $ifd1           Optional next IFD, typically thumbnails.
     * @param MakerNotesMetadata|null $makerNotes     Decoded maker note metadata provided by vendor decoders.
     * @param list<Ifd>               $subsequentIfds Additional linked IFDs discovered via the next-pointer chain.
     * @param array<int, Ifd>         $subIfds        Parsed SubIFDs indexed by their file offsets.
     */
    public function __construct(
        public Ifd $ifd0,
        public ?Ifd $exifIfd,
        public ?Ifd $gpsIfd,
        public ?Ifd $interopIfd,
        public ?Ifd $ifd1,
        public ?MakerNotesMetadata $makerNotes = null,
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
    }

    /**
     * Returns the decoded maker note metadata when a decoder is available.
     */
    public function makerNotes(): ?MakerNotesMetadata
    {
        return $this->makerNotes;
    }

    /**
     * Indicates whether the maker note is considered safe to modify according to EXIF tag 0xC635.
     */
    public function makerNoteSafety(): ?bool
    {
        $value = $this->int($this->exifIfd, ExifTag::MAKER_NOTE_SAFETY);

        return match ($value) {
            0       => false,
            1       => true,
            default => null,
        };
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

        if ($serial !== null) {
            return $serial;
        }

        return $this->bodySerialNumber();
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
     * Returns the EXIF orientation value if present.
     *
     * @return int|null
     */
    public function orientation(): ?int
    {
        return $this->int($this->ifd0, ExifTag::ORIENTATION);
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
     * Returns the colour space identifier if present.
     *
     * @return int|null
     */
    public function colorSpace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::COLOR_SPACE);
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
    public function flashpixVersion(): ?string
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

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::IMAGE_DESCRIPTION);
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

        if ($value !== null) {
            return $value;
        }

        return $this->xpComment();
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

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::SOFTWARE);
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

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::ARTIST);
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

        if ($value !== null) {
            return $value;
        }

        return $this->str($this->ifd0, ExifTag::IMAGE_EDITOR_LEGACY);
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

        return $keywords === [] ? null : array_values($keywords);
    }

    /**
     * Returns the tile width defined for the primary or thumbnail image data.
     */
    public function tileWidth(): ?int
    {
        $width = $this->int($this->ifd0, ExifTag::TILE_WIDTH);

        return $width ?? $this->int($this->ifd1, ExifTag::TILE_WIDTH);
    }

    /**
     * Returns the tile length defined for the primary or thumbnail image data.
     */
    public function tileLength(): ?int
    {
        $length = $this->int($this->ifd0, ExifTag::TILE_LENGTH);

        return $length ?? $this->int($this->ifd1, ExifTag::TILE_LENGTH);
    }

    /**
     * Returns the tile offsets defined for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function tileOffsets(): ?array
    {
        $offsets = $this->numericList($this->ifd0, ExifTag::TILE_OFFSETS);

        return $offsets ?? $this->numericList($this->ifd1, ExifTag::TILE_OFFSETS);
    }

    /**
     * Returns the tile byte counts defined for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function tileByteCounts(): ?array
    {
        $counts = $this->numericList($this->ifd0, ExifTag::TILE_BYTE_COUNTS);

        return $counts ?? $this->numericList($this->ifd1, ExifTag::TILE_BYTE_COUNTS);
    }

    /**
     * Returns the strip offsets defined for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function stripOffsets(): ?array
    {
        $offsets = $this->numericList($this->ifd0, ExifTag::STRIP_OFFSETS);

        return $offsets ?? $this->numericList($this->ifd1, ExifTag::STRIP_OFFSETS);
    }

    /**
     * Returns the strip byte counts for the primary or thumbnail image data.
     *
     * @return list<int>|null
     */
    public function stripByteCounts(): ?array
    {
        $counts = $this->numericList($this->ifd0, ExifTag::STRIP_BYTE_COUNTS);

        return $counts ?? $this->numericList($this->ifd1, ExifTag::STRIP_BYTE_COUNTS);
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
     * Returns the JPEG thumbnail offset, preferring the dedicated thumbnail IFD when present.
     */
    public function jpegThumbnailOffset(): ?int
    {
        $offset = $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT);

        return $offset ?? $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT);
    }

    /**
     * Returns the JPEG thumbnail byte length, preferring the dedicated thumbnail IFD when present.
     */
    public function jpegThumbnailLength(): ?int
    {
        $length = $this->int($this->ifd1, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);

        return $length ?? $this->int($this->ifd0, ExifTag::JPEG_INTERCHANGE_FORMAT_LENGTH);
    }

    /**
     * Indicates whether a JPEG thumbnail is referenced by the EXIF structure.
     */
    public function hasThumbnail(): ?bool
    {
        $offset = $this->jpegThumbnailOffset();
        $length = $this->jpegThumbnailLength();

        if ($offset === null && $length === null) {
            return null;
        }

        if ($offset === null || $length === null) {
            return false;
        }

        return $length > 0;
    }

    /**
     * Returns the preview image offset stored in the EXIF 3.0 preview tags.
     */
    public function previewImageOffset(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::PREVIEW_IMAGE_START);
    }

    /**
     * Returns the preview image byte length stored in the EXIF 3.0 preview tags.
     */
    public function previewImageLength(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::PREVIEW_IMAGE_LENGTH);
    }

    /**
     * Indicates whether an EXIF 3.0 preview image is referenced.
     */
    public function hasPreviewImage(): ?bool
    {
        $offset = $this->previewImageOffset();
        $length = $this->previewImageLength();

        if ($offset === null && $length === null) {
            return null;
        }

        if ($offset === null || $length === null) {
            return false;
        }

        return $length > 0;
    }

    /**
     * Returns the preview image width in pixels.
     */
    public function previewImageWidth(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::PREVIEW_IMAGE_WIDTH);
    }

    /**
     * Returns the preview image height in pixels.
     */
    public function previewImageHeight(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::PREVIEW_IMAGE_HEIGHT);
    }

    /**
     * Returns the preview image colour space identifier when present.
     */
    public function previewColorSpace(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::PREVIEW_IMAGE_COLOR_SPACE);
    }

    /**
     * Returns the raw preview modification datetime string.
     */
    public function previewDateTimeRaw(): ?string
    {
        return $this->rawString($this->exifIfd, ExifTag::PREVIEW_DATE_TIME);
    }

    /**
     * Returns the raw preview digitised datetime string.
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
        return $this->numericList($this->exifIfd, ExifTag::COMPONENTS_CONFIGURATION);
    }

    /**
     * Returns the component configuration labels in human readable form.
     *
     * @return list<string>|null
     */
    public function componentsConfigurationLabels(): ?array
    {
        $components = $this->componentsConfiguration();

        return $components !== null ? ValueConverters::componentsConfigurationLabels($components) : null;
    }

    /**
     * Returns the component configuration as a formatted string.
     */
    public function componentsConfigurationDescription(): ?string
    {
        $components = $this->componentsConfiguration();

        return $components !== null ? ValueConverters::componentsConfigurationDescription($components) : null;
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
        $raw = $this->rawString($this->exifIfd, ExifTag::USER_COMMENT);

        return $raw !== null ? $this->decodeUserComment($raw) : null;
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

            return null;
        }

        $iso = $this->int($this->exifIfd, ExifTag::ISO_SPEED);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::STANDARD_OUTPUT_SENSITIVITY);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::RECOMMENDED_EXPOSURE_INDEX);
        if ($iso !== null) {
            return $iso;
        }

        $iso = $this->int($this->exifIfd, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
        if ($iso !== null) {
            return $iso;
        }

        return $this->int($this->ifd0, ExifTag::PHOTOGRAPHIC_SENSITIVITY);
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
        $raw = $this->value($this->exifIfd, ExifTag::SHUTTER_SPEED_VALUE);

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
     * Returns the camera exposure program code if present.
     *
     * @return int|null
     */
    public function exposureProgram(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::EXPOSURE_PROGRAM);
    }

    /**
     * Returns the metering mode code if present.
     *
     * @return int|null
     */
    public function meteringMode(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::METERING_MODE);
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
     * Returns the white balance mode if present.
     *
     * @return int|null
     */
    public function whiteBalance(): ?int
    {
        return $this->int($this->exifIfd, ExifTag::WHITE_BALANCE);
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

        return $signature !== null
            ? $signature
            : $this->str($this->ifd0, ExifTag::CAMERA_CALIBRATION_SIGNATURE);
    }

    /**
     * Returns the DNG profile calibration signature when available.
     */
    public function profileCalibrationSignature(): ?string
    {
        $signature = $this->str($this->exifIfd, ExifTag::PROFILE_CALIBRATION_SIGNATURE);

        return $signature !== null
            ? $signature
            : $this->str($this->ifd0, ExifTag::PROFILE_CALIBRATION_SIGNATURE);
    }

    /**
     * Returns the hue/saturation/value profile adjustment maps.
     *
     * @return array{dimensions:list<int>|null,map1:list<float>|null,map2:list<float>|null,map3:list<float>|null}|null
     */
    public function profileHueSatMap(): ?array
    {
        foreach ($this->profileIfds() as $ifd) {
            $dimensions = $this->numericList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DIMS);
            $map1       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_1);
            $map2       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_2);
            $map3       = $this->rationalList($ifd, ExifTag::PROFILE_HUE_SAT_MAP_DATA_3);

            if ($dimensions === null && $map1 === null && $map2 === null && $map3 === null) {
                continue;
            }

            return [
                'dimensions' => $dimensions,
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
            if ($values === null || $values === []) {
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
        foreach ($this->profileIfds() as $ifd) {
            $values = $this->rationalList($ifd, ExifTag::PROFILE_GAIN_TABLE_MAP);
            if ($values === null || $values === []) {
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

        return [(int) $values[0], (int) $values[1]];
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

        if (!is_int($width) || !is_int($height) || $width <= 0 || $height <= 0) {
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
        $value = $this->value($this->exifIfd, ExifTag::BATTERY_LEVEL);

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
        return $this->str($this->exifIfd, ExifTag::DATETIME_ORIGINAL);
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
        return $this->str($this->exifIfd, ExifTag::DATETIME_DIGITIZED);
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
                    $component = $component->toInt('EXIF numeric list component');
                }

                if (!is_int($component) && !is_float($component)) {
                    return null;
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
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_ORIGINAL);
    }

    /**
     * Returns the normalized offset time for DateTimeDigitized.
     */
    public function offsetTimeDigitized(): ?string
    {
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME_DIGITIZED);
    }

    /**
     * Returns the normalized offset time for the IFD0 ModifyDate/DateTime tag.
     */
    public function offsetTime(): ?string
    {
        return $this->normalizedOffset($this->exifIfd, ExifTag::OFFSET_TIME);
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
            $primaryOffset   = $this->derivedOffsetFromTimeZoneOffset(0);
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

        return null;
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
     * @return list<Ifd>
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
            if ($ifd instanceof Ifd) {
                $sources[] = $ifd;
            }
        }

        foreach ($this->subIfds as $ifd) {
            if ($ifd instanceof Ifd) {
                $sources[] = $ifd;
            }
        }

        return $sources;
    }

    /**
     * @return list<Ifd>
     */
    private function profileIfds(): array
    {
        $candidates = [];

        foreach ($this->profileSourceIfds() as $ifd) {
            if ($ifd->get(ExifTag::PROFILE_HUE_SAT_MAP_DIMS) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_LOOK_TABLE_DIMS) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_TONE_CURVE) instanceof IfdEntry
                || $ifd->get(ExifTag::PROFILE_GAIN_TABLE_MAP) instanceof IfdEntry) {
                $candidates[] = $ifd;
            }
        }

        return $candidates;
    }

    /**
     * Returns a string value from the given IFD if present.
     *
     * @return string|null
     */

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
        $value = $this->value($this->ifd0, ExifTag::COMPRESSION);

        return Compression::fromExifValue($value);
    }

    /**
     * Returns the photometric interpretation enum.
     */
    public function photometric(): ?Photometric
    {
        $value = $this->value($this->ifd0, ExifTag::PHOTOMETRIC_INTERPRETATION);

        return Photometric::fromExifValue($value);
    }

    /**
     * Returns the planar configuration enum when recorded.
     */
    public function planarConfiguration(): ?PlanarConfiguration
    {
        $value = $this->value($this->ifd0, ExifTag::PLANAR_CONFIGURATION);

        return PlanarConfiguration::fromExifValue($value);
    }

    /**
     * Returns the resolution unit enum for the reported X/Y resolution values.
     */
    public function resolutionUnit(): ?ResolutionUnit
    {
        $value = $this->value($this->ifd0, ExifTag::RESOLUTION_UNIT);

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
        $value = $this->value($this->ifd0, ExifTag::YCBCR_POSITIONING);

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
            $normalized = array_values($values);
            if (count($normalized) === 2) {
                return [(int) $normalized[0], (int) $normalized[1]];
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
        $value = $this->value($this->ifd0, ExifTag::YCBCR_COEFFICIENTS);

        if ($value instanceof ExifNumericList) {
            $coeffs = array_map(static fn (int|float $component): float => (float) $component, $value->values);
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
        $value = $this->value($this->exifIfd, ExifTag::EXPOSURE_MODE);

        return ExposureMode::fromExifValue($value);
    }

    /**
     * Returns the gain control enum describing in-camera amplification.
     */
    public function gainControl(): ?GainControl
    {
        $value = $this->value($this->exifIfd, ExifTag::GAIN_CONTROL);

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
        $value = $this->value($this->exifIfd, ExifTag::SENSING_METHOD);

        return SensingMethod::fromExifValue($value);
    }

    /**
     * Returns the light source enum describing the scene illumination.
     */
    public function lightSource(): ?LightSource
    {
        $value = $this->value($this->exifIfd, ExifTag::LIGHT_SOURCE);
        if (is_int($value)) {
            return LightSource::tryFrom($value);
        }

        if (is_string($value) && $value !== '') {
            return LightSource::tryFrom((int) $value);
        }

        return null;
    }

    /**
     * Returns the scene capture type enum when recorded.
     */
    public function sceneCaptureType(): ?SceneCaptureType
    {
        $value = $this->value($this->exifIfd, ExifTag::SCENE_CAPTURE_TYPE);
        if (is_int($value)) {
            return SceneCaptureType::tryFrom($value);
        }

        if (is_string($value) && $value !== '') {
            return SceneCaptureType::tryFrom((int) $value);
        }

        return null;
    }

    /**
     * Returns the subject distance range enum when provided.
     */
    public function subjectDistanceRange(): ?SubjectDistanceRange
    {
        $value = $this->value($this->exifIfd, ExifTag::SUBJECT_DISTANCE_RANGE);

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
        $value = $this->value($this->exifIfd, ExifTag::SUBJECT_AREA);

        if ($value instanceof ExifNumericList) {
            return array_map(static fn (int|float $component): int => (int) $component, $value->values);
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
        $value = $this->value($ifd, $tag);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = rtrim($value, "\0");

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Returns an integer value from the given IFD if present.
     *
     * @return int|null
     */
    private function int(?Ifd $ifd, int $tag): ?int
    {
        $value = $this->value($ifd, $tag);

        if ($value instanceof ExifNumericList) {
            $first = $value->values[0] ?? null;
            if (is_int($first)) {
                return $first;
            }

            if (is_float($first)) {
                return (int) $first;
            }

            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Returns a rational or numeric value converted to float if present in the given IFD.
     *
     * @return float|null
     */
    private function rational(?Ifd $ifd, int $tag): ?float
    {
        $value = $this->value($ifd, $tag);

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
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    private function valueFromGpsOrExif(int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        $value = $this->value($this->gpsIfd, $tag);

        if ($value !== null) {
            return $value;
        }

        return $this->value($this->exifIfd, $tag);
    }

    /**
     * Retrieves the raw entry value for the provided tag.
     *
     * @return int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
     */
    private function value(?Ifd $ifd, int $tag): int|float|string|ExifRational|ExifRationalList|ExifNumericList|null
    {
        if (!$ifd instanceof Ifd) {
            return null;
        }

        $entry = $ifd->get($tag);

        return $entry?->value;
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
            return array_map(static fn (int|float $v): float => (float) $v, $value->values);
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
            return null;
        }

        $prefix   = substr($raw, 0, 8);
        $encoding = strtoupper(trim($prefix, "\0 "));
        $content  = substr($raw, 8);
        $content  = trim($content, "\0");

        return match ($encoding) {
            'ASCII', 'UTF8', '' => $content !== '' ? $content : null,
            'UNICODE' => $this->decodeUnicodeComment($content),
            'JIS'     => $this->decodeJisComment($content),
            default   => $content !== '' ? $content : null,
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
     * Derives a canonical offset string from the legacy TimeZoneOffset tag when available.
     */
    private function derivedOffsetFromTimeZoneOffset(int $component = 0): ?string
    {
        $values = $this->timeZoneOffsetMinutes();

        if ($values === null || !array_key_exists($component, $values)) {
            return null;
        }

        $minutes = $values[$component];
        $sign    = $minutes < 0 ? '-' : '+';
        $absolute = abs($minutes);
        $hours    = intdiv($absolute, 60);
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

            return $first !== null ? (string) (int) $first : null;
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
