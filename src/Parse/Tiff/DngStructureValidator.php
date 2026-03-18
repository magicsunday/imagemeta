<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRational;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Value\Enum\Compression;
use MagicSunday\ImageMeta\Value\Enum\Photometric;

use function array_any;
use function in_array;
use function intdiv;
use function is_int;
use function is_string;
use function ord;
use function preg_match;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

/**
 * Validates DNG structural rules, opcodes, CFA, raw data, and miscellaneous constraints.
 *
 * DNG 1.7.1.0 defines structural framing for opcode lists, original raw file data,
 * RGB tables, image stats, image sequence info, and various enum/domain checks
 * validated by this class.
 */
final readonly class DngStructureValidator
{
    public function __construct(
        private DngValidationSupport $support,
    ) {
    }

    /**
     * DNG NewSubFileType-to-PhotometricInterpretation rules.
     * Depth map IFDs (type 8/9) require 51177; semantic mask IFDs (type 65540) require 52527.
     *
     * @var array<int, int>
     */
    private const array DNG_ROLE_PHOTOMETRIC   = [
        8     => 51177,
        9     => 51177,
        65540 => 52527,
    ];

    /**
     * DNG tags restricted to IFD 0 per DNG 1.7.1.0.
     *
     * @var list<int>
     */
    private const array DNG_IFD0_ONLY_TAGS     = [
        DngTag::DNG_VERSION,
        DngTag::DNG_BACKWARD_VERSION,
        DngTag::UNIQUE_CAMERA_MODEL,
        DngTag::LOCALIZED_CAMERA_MODEL,
        DngTag::AS_SHOT_NEUTRAL,
        DngTag::AS_SHOT_WHITE_XY,
        DngTag::BASELINE_EXPOSURE,
        DngTag::BASELINE_NOISE,
        DngTag::BASELINE_SHARPNESS,
        DngTag::CAMERA_SERIAL_NUMBER,
        DngTag::DNG_PRIVATE_DATA,
        DngTag::MAKER_NOTE_SAFETY,
        DngTag::RAW_DATA_UNIQUE_ID,
        DngTag::ANALOG_BALANCE,
        DngTag::AS_SHOT_ICC_PROFILE,
        DngTag::AS_SHOT_PRE_PROFILE_MATRIX,
        DngTag::CURRENT_ICC_PROFILE,
        DngTag::CURRENT_PRE_PROFILE_MATRIX,
    ];

    /**
     * Opcode-list tags defined by DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DNG_OPCODE_LIST_TAGS   = [
        DngTag::OPCODE_LIST_1 => 'OpcodeList1',
        DngTag::OPCODE_LIST_2 => 'OpcodeList2',
        DngTag::OPCODE_LIST_3 => 'OpcodeList3',
    ];

    /**
     * Bytes per RGB entry keyed by RGBTables PixelType.
     *
     * @var array<int, int>
     */
    private const array RGB_TABLES_PIXEL_BYTES = [
        0 => 3,
        1 => 6,
        2 => 12,
    ];

    /**
     * DNG digest tags that must be BYTE[16] per DNG 1.7.1.0.
     *
     * @var array<int, string>
     */
    private const array DIGEST_TAGS            = [
        DngTag::PREVIEW_SETTINGS_DIGEST  => 'PreviewSettingsDigest',
        DngTag::RAW_IMAGE_DIGEST         => 'RawImageDigest',
        DngTag::ORIGINAL_RAW_FILE_DIGEST => 'OriginalRawFileDigest',
        DngTag::NEW_RAW_IMAGE_DIGEST     => 'NewRawImageDigest',
    ];

    /**
     * Validates that DNG files include the required Orientation tag.
     */
    public function validateDngRequiredOrientation(Ifd $ifd): void
    {
        if (!$ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        if (!$ifd->get(ExifTag::ORIENTATION) instanceof IfdEntry) {
            throw new ParseError(
                'DNG requires Orientation tag in IFD0 per DNG 1.7.1.0.',
                1484,
            );
        }
    }

    /**
     * Requires UniqueCameraModel in IFD0 when DNGVersion is present.
     */
    public function validateDngRequiredUniqueCameraModel(Ifd $ifd): void
    {
        if (!$ifd->get(DngTag::DNG_VERSION) instanceof IfdEntry) {
            return;
        }

        if (!$ifd->get(DngTag::UNIQUE_CAMERA_MODEL) instanceof IfdEntry) {
            throw new ParseError(
                'DNG requires UniqueCameraModel tag in IFD0 per DNG 1.7.1.0.',
                2046,
            );
        }
    }

    /**
     * Validates that depth map and semantic mask IFDs use their required PhotometricInterpretation.
     */
    public function validateDngRolePhotometric(Ifd $ifd): void
    {
        $subfileEntry = $ifd->get(TiffTag::NEW_SUBFILE_TYPE);

        if (!$subfileEntry instanceof IfdEntry || !is_int($subfileEntry->value)) {
            return;
        }

        $required     = self::DNG_ROLE_PHOTOMETRIC[$subfileEntry->value] ?? null;

        if ($required === null) {
            return;
        }

        $photoEntry   = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);
        $photoValue   = $photoEntry instanceof IfdEntry && is_int($photoEntry->value) ? $photoEntry->value : null;

        if ($photoValue !== $required) {
            throw new ParseError(
                sprintf(
                    'DNG IFD with NewSubFileType %d requires PhotometricInterpretation %d per DNG 1.7.1.0, got %s.',
                    $subfileEntry->value,
                    $required,
                    $photoValue !== null ? (string) $photoValue : 'none',
                ),
                2018,
            );
        }
    }

    /**
     * Rejects DNG IFD0-only tags found in additional IFDs.
     */
    public function validateDngIfd0OnlyTags(Ifd $ifd): void
    {
        foreach (self::DNG_IFD0_ONLY_TAGS as $tag) {
            if ($ifd->get($tag) instanceof IfdEntry) {
                throw new ParseError(
                    sprintf(
                        'DNG tag 0x%04X is restricted to IFD 0 per DNG 1.7.1.0 but found in additional IFD.',
                        $tag,
                    ),
                    2021,
                );
            }
        }
    }

    /**
     * Validates DNG JPEG XL tag constraints per DNG 1.7.1.0 §JXL tags.
     *
     * JXLEffort must be 1–9, JXLDecodeSpeed must be 1–4, and all three
     * JXL tags may only appear with Compression = 52546 (JPEG XL).
     */
    public function validateDngJxlTags(Ifd $ifd): void
    {
        $jxlDistance    = $ifd->get(DngTag::JXL_DISTANCE);
        $jxlEffort      = $ifd->get(DngTag::JXL_EFFORT);
        $jxlDecodeSpeed = $ifd->get(DngTag::JXL_DECODE_SPEED);

        $hasJxlTags     = $jxlDistance instanceof IfdEntry
            || $jxlEffort instanceof IfdEntry
            || $jxlDecodeSpeed instanceof IfdEntry;

        if (!$hasJxlTags) {
            return;
        }

        $compression    = $ifd->get(ExifTag::COMPRESSION);

        if (!$compression instanceof IfdEntry || !is_int($compression->value) || $compression->value !== Compression::JpegXl->value) {
            throw new ParseError(
                'JXL tags (JXLDistance, JXLEffort, JXLDecodeSpeed) require Compression = 52546 (JPEG XL).',
                2024,
            );
        }

        if (($jxlEffort instanceof IfdEntry) && is_int($jxlEffort->value) && ($jxlEffort->value < 1 || $jxlEffort->value > 9)) {
            throw new ParseError(
                sprintf('JXLEffort must be 1–9, got %d.', $jxlEffort->value),
                2022,
            );
        }

        if (($jxlDecodeSpeed instanceof IfdEntry) && is_int($jxlDecodeSpeed->value) && ($jxlDecodeSpeed->value < 1 || $jxlDecodeSpeed->value > 4)) {
            throw new ParseError(
                sprintf('JXLDecodeSpeed must be 1–4, got %d.', $jxlDecodeSpeed->value),
                2023,
            );
        }

        $spp            = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if (($spp instanceof IfdEntry) && is_int($spp->value) && ($spp->value !== 1) && ($spp->value !== 3)) {
            throw new ParseError(
                sprintf('JPEG XL SamplesPerPixel must be 1 or 3, got %d.', $spp->value),
                2027,
            );
        }

        $photo          = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (($photo instanceof IfdEntry) && is_int($photo->value) && !in_array($photo->value, [0, 1, 2, 4, 32803, 34892, 51177, 52527], true)) {
            throw new ParseError(
                sprintf('JPEG XL PhotometricInterpretation %d is not allowed.', $photo->value),
                2028,
            );
        }
    }

    /**
     * Validates CFA photometric cross-tag requirements per DNG 1.7.1.0.
     *
     * When PhotometricInterpretation is CFA (32803), both CFARepeatPatternDim
     * and CFAPattern must be present in the same IFD.
     */
    public function validateDngCfaPhotometric(Ifd $ifd): void
    {
        $photo    = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || $photo->value !== 32803) {
            return;
        }

        if (!$ifd->get(DngTag::CFA_REPEAT_PATTERN_DIM) instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFARepeatPatternDim in the same IFD.',
                2025,
            );
        }

        $cfaEntry = $ifd->get(ExifTag::CFA_PATTERN);

        if (!$cfaEntry instanceof IfdEntry) {
            throw new ParseError(
                'CFA photometric (32803) requires CFAPattern in the same IFD.',
                2026,
            );
        }

        if ($ifd->get(DngTag::CFA_PLANE_COLOR) instanceof IfdEntry) {
            return;
        }

        $cfaValue = $cfaEntry->value;

        if (!$cfaValue instanceof ExifNumericList) {
            return;
        }

        if (array_any(
            $cfaValue->values,
            static fn (int|float|UInt64 $color): bool => is_int($color) && $color > 2,
        )) {
            throw new ParseError(
                'Non-RGB CFA images require CFAPlaneColor per DNG 1.7.1.0.',
                2038,
            );
        }
    }

    /**
     * Validates CFALayout (0xC617) value domain and version gating per DNG 1.7.1.0.
     *
     * Allowed values are 1..9. Values 6..9 require DNGBackwardVersion >= 1.3.0.0.
     */
    public function validateDngCfaLayoutDomain(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::CFA_LAYOUT);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!is_int($entry->value) || $entry->value < 1 || $entry->value > 9) {
            throw new ParseError(
                sprintf('CFALayout value must be 1..9, got %d.', is_int($entry->value) ? $entry->value : -1),
                1584,
            );
        }

        if ($entry->value >= 6) {
            $bwVer = $this->support->getEffectiveDngBackwardVersion($ifd);

            if (($bwVer !== null) && $this->support->dngVersionLessThan($bwVer, [1, 3, 0, 0])) {
                throw new ParseError(
                    sprintf(
                        'CFALayout value %d requires DNGBackwardVersion >= 1.3.0.0, got %d.%d.%d.%d.',
                        $entry->value,
                        $bwVer[0],
                        $bwVer[1],
                        $bwVer[2],
                        $bwVer[3],
                    ),
                    1585,
                );
            }
        }
    }

    /**
     * Validates DNG OpcodeList1/2/3 structural framing.
     *
     * DNG 1.7.1.0 Chapter 7 ("Opcode List Processing") defines big-endian list framing:
     * list count (uint32), then per opcode: OpcodeID (uint32), DNGVersion (uint32),
     * Flags (uint32), ParamByteCount (uint32), and ParamByteCount payload bytes.
     * The same framing was introduced with these tags in DNG 1.3.0.0 and remains
     * unchanged in later versions including DNG 1.7.1.0.
     */
    public function validateDngOpcodeLists(Ifd $ifd): void
    {
        foreach (self::DNG_OPCODE_LIST_TAGS as $tag => $tagName) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
                throw new ParseError(
                    sprintf('%s must use UNDEFINED type, got %d.', $tagName, $entry->type),
                    1633,
                );
            }

            if (!is_string($entry->value)) {
                throw new ParseError(
                    sprintf('%s must decode to raw bytes.', $tagName),
                    1634,
                );
            }

            $this->validateDngOpcodeListPayload($tagName, $entry->value);
        }
    }

    /**
     * Validates OriginalRawFileData payload framing.
     *
     * DNG 1.7.1.0 ("OriginalRawFileData") defines UNDEFINED payload bytes in big-endian
     * block order with four compressed forks and four 4-byte type/creator fields.
     * Trailing bytes are allowed for forward compatibility.
     */
    public function validateDngOriginalRawFileData(Ifd $ifd): void
    {
        $entry   = $ifd->get(DngTag::ORIGINAL_RAW_FILE_DATA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_UNDEFINED) {
            throw new ParseError(
                sprintf('OriginalRawFileData must use UNDEFINED type, got %d.', $entry->type),
                1626,
            );
        }

        if (!is_string($entry->value)) {
            throw new ParseError('OriginalRawFileData must decode to raw bytes.', 1627);
        }

        $payload = $entry->value;
        $offset  = 0;

        $offset  = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw data fork');
        $offset  = $this->validateDngOriginalRawForkBlock($payload, $offset, 'original raw resource fork');
        $offset  = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file type');
        $offset  = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'original raw macOS file creator');
        $offset  = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM data fork');
        $offset  = $this->validateDngOriginalRawForkBlock($payload, $offset, 'sidecar THM resource fork');
        $offset  = $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file type');
        $this->consumeDngOriginalRawFixedBlock($payload, $offset, 'sidecar THM macOS file creator');
    }

    /**
     * Validates DNG RGBTables payload structure per DNG 1.7.1.0.
     *
     * Top-level: NumTables (1..20), CompositeMethod ({0,1}).
     * Per-table: Divisions (2..32), PixelType ({0,1,2}), GammaEncoding (0..4),
     * ColorPrimaries (0..4), GamutExtension ({0,1}), then Divisions^3 entries.
     */
    public function validateDngRgbTables(Ifd $ifd): void
    {
        $entry           = $ifd->get(DngTag::RGB_TABLES);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload         = $entry->value;
        PayloadGuard::ensureMinimumLength($payload, 8, 'RGBTables payload', 1527);
        $length          = strlen($payload);

        $numTables       = $this->support->unpackU32(substr($payload, 0, 4));
        $compositeMethod = $this->support->unpackU32(substr($payload, 4, 4));

        if ($numTables < 1 || $numTables > 20) {
            throw new ParseError(
                sprintf('RGBTables NumTables must be 1..20, got %d.', $numTables),
                1528,
            );
        }

        if (($compositeMethod !== 0) && ($compositeMethod !== 1)) {
            throw new ParseError(
                sprintf('RGBTables CompositeMethod must be 0 or 1, got %d.', $compositeMethod),
                1529,
            );
        }

        $offset          = 8;
        $zeroNameCount   = 0;

        for ($t = 0; $t < $numTables; ++$t) {
            if (($length - $offset) < 2) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d header.', $t),
                    1530,
                );
            }

            $nameLen        = $this->support->unpackU16(substr($payload, $offset, 2));
            $offset += 2;

            if ($nameLen === 0) {
                ++$zeroNameCount;
            }

            $offset += $nameLen;

            if (($length - $offset) < 5) {
                throw new ParseError(
                    sprintf('RGBTables payload truncated at table %d fields.', $t),
                    2061,
                );
            }

            $divisions      = ord($payload[$offset]);
            $pixelType      = ord($payload[$offset + 1]);
            $gammaEncoding  = ord($payload[$offset + 2]);
            $colorPrimaries = ord($payload[$offset + 3]);
            $gamutExtension = ord($payload[$offset + 4]);
            $offset += 5;

            if ($divisions < 2 || $divisions > 32) {
                throw new ParseError(
                    sprintf('RGBTables table %d Divisions must be 2..32, got %d.', $t, $divisions),
                    1531,
                );
            }

            if (!isset(self::RGB_TABLES_PIXEL_BYTES[$pixelType])) {
                throw new ParseError(
                    sprintf('RGBTables table %d PixelType must be 0..2, got %d.', $t, $pixelType),
                    1532,
                );
            }

            if ($gammaEncoding > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d GammaEncoding must be 0..4, got %d.', $t, $gammaEncoding),
                    1533,
                );
            }

            if ($colorPrimaries > 4) {
                throw new ParseError(
                    sprintf('RGBTables table %d ColorPrimaries must be 0..4, got %d.', $t, $colorPrimaries),
                    1534,
                );
            }

            if ($gamutExtension > 1) {
                throw new ParseError(
                    sprintf('RGBTables table %d GamutExtension must be 0 or 1, got %d.', $t, $gamutExtension),
                    1535,
                );
            }

            $tableDataSize  = $divisions * $divisions * $divisions * self::RGB_TABLES_PIXEL_BYTES[$pixelType];
            $offset += $tableDataSize;
        }

        if (($numTables > 1) && ($zeroNameCount > 1)) {
            throw new ParseError(
                sprintf('RGBTables allows at most one unnamed table when NumTables > 1, got %d.', $zeroNameCount),
                1536,
            );
        }

        if ($offset !== $length) {
            throw new ParseError(
                sprintf('RGBTables payload length mismatch: expected %d bytes, got %d.', $offset, $length),
                1537,
            );
        }
    }

    /**
     * Validates SemanticName/SemanticInstanceID conformance in Semantic Mask IFDs.
     *
     * A Semantic Mask IFD is identified by PhotometricInterpretation = 52527.
     * SemanticName is required in that context per DNG 1.6+.
     */
    public function validateDngSemanticMaskIdentity(Ifd $ifd): void
    {
        $photo     = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PhotometricMask->value) {
            return;
        }

        $nameEntry = $ifd->get(DngTag::SEMANTIC_NAME);

        if (!$nameEntry instanceof IfdEntry) {
            throw new ParseError(
                'SemanticName is required in Semantic Mask IFD per DNG 1.6+.',
                1538,
            );
        }

        if ($nameEntry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('SemanticName must use ASCII type, got %d.', $nameEntry->type),
                1539,
            );
        }

        if (!is_string($nameEntry->value) || $nameEntry->value === '') {
            throw new ParseError(
                'SemanticName must not be empty in Semantic Mask IFD.',
                1540,
            );
        }
    }

    /**
     * Validates MaskSubArea (0xCD38) in Semantic Mask IFDs.
     *
     * MaskSubArea must use type LONG with count 4: (T_crop, L_crop, W_full, H_full).
     * Geometric constraints require T_crop + ImageLength <= H_full and
     * L_crop + ImageWidth <= W_full. If geometric constraints fail the tag
     * is ignored per DNG 1.6+ spec (no ParseError for geometry).
     */
    public function validateDngMaskSubArea(Ifd $ifd): void
    {
        $photo = $ifd->get(ExifTag::PHOTOMETRIC_INTERPRETATION);

        if (!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== Photometric::PhotometricMask->value) {
            return;
        }

        $entry = $ifd->get(DngTag::MASK_SUB_AREA);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG) {
            throw new ParseError(
                sprintf('MaskSubArea must use LONG type, got %d.', $entry->type),
                1541,
            );
        }

        if ($entry->count !== 4) {
            throw new ParseError(
                sprintf('MaskSubArea must have count 4, got %d.', $entry->count),
                1542,
            );
        }
    }

    /**
     * Validates DNG ImageStats (0xCD46) payload structure per DNG 1.7.1.0.
     *
     * All ImageStats data is stored in big-endian byte order regardless of TIFF
     * file byte order. Payload: LONG child-count N, then N child entries each
     * containing LONG childTagCode, LONG byteLength L, and L bytes of data.
     * Duplicate child tag codes are rejected.
     */
    public function validateDngImageStats(Ifd $ifd): void
    {
        $entry      = $ifd->get(DngTag::IMAGE_STATS);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload    = $entry->value;
        PayloadGuard::ensureMinimumLength($payload, 4, 'ImageStats payload', 1543);
        $length     = strlen($payload);

        // ImageStats is always big-endian
        $childCount = Unpack::int('N', substr($payload, 0, 4), 'ImageStats child count');
        $offset     = 4;
        $seenTags   = [];

        for ($i = 0; $i < $childCount; ++$i) {
            if ($offset + 8 > $length) {
                throw new ParseError(
                    sprintf('ImageStats child entry %d truncated at header (offset %d, length %d).', $i, $offset, $length),
                    1544,
                );
            }

            $childTag            = Unpack::int('N', substr($payload, $offset, 4), 'ImageStats child tag');
            $childLength         = Unpack::int('N', substr($payload, $offset + 4, 4), 'ImageStats child length');
            $offset += 8;

            if ($offset + $childLength > $length) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d payload truncated (need %d bytes at offset %d, have %d).', $childTag, $childLength, $offset, $length),
                    1545,
                );
            }

            if (isset($seenTags[$childTag])) {
                throw new ParseError(
                    sprintf('ImageStats child tag %d appears more than once.', $childTag),
                    1546,
                );
            }

            $seenTags[$childTag] = true;
            $offset += $childLength;
        }
    }

    /**
     * Validates DNG ImageSequenceInfo payload structure per DNG 1.7.1.0.
     *
     * Payload: SequenceID (NUL-terminated, min 8 chars), SequenceType (NUL-terminated, min 1 char),
     * FrameInfo (NUL-terminated), Index (uint32 big-endian), Count (uint32 big-endian), Final (uint8).
     */
    public function validateDngImageSequenceInfo(Ifd $ifd): void
    {
        $entry      = $ifd->get(DngTag::IMAGE_SEQUENCE_INFO);

        if (!$entry instanceof IfdEntry || !is_string($entry->value)) {
            return;
        }

        $payload    = $entry->value;
        $length     = strlen($payload);
        $offset     = 0;

        // SequenceID: NUL-terminated, minimum 8 chars before NUL
        $nulPos     = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceID must be NUL-terminated.', 1521);
        }

        $seqIdLen   = $nulPos - $offset;

        if ($seqIdLen < 8) {
            throw new ParseError(
                sprintf('ImageSequenceInfo SequenceID must be at least 8 characters, got %d.', $seqIdLen),
                1522,
            );
        }

        $offset     = $nulPos + 1;

        // SequenceType: NUL-terminated, minimum 1 char
        $nulPos     = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo SequenceType must be NUL-terminated.', 1523);
        }

        $seqTypeLen = $nulPos - $offset;

        if ($seqTypeLen < 1) {
            throw new ParseError('ImageSequenceInfo SequenceType must be at least 1 character.', 1524);
        }

        $offset     = $nulPos + 1;

        // FrameInfo: NUL-terminated (may be empty)
        $nulPos     = strpos($payload, "\0", $offset);

        if ($nulPos === false) {
            throw new ParseError('ImageSequenceInfo FrameInfo must be NUL-terminated.', 1525);
        }

        $offset     = $nulPos + 1;

        // Index(4) + Count(4) + Final(1) = 9 bytes remaining
        if (($length - $offset) < 9) {
            throw new ParseError(
                sprintf('ImageSequenceInfo payload truncated: need 9 bytes for Index/Count/Final, got %d.', $length - $offset),
                1526,
            );
        }
    }

    /**
     * Validates DNG digest tags (RawImageDigest, OriginalRawFileDigest, NewRawImageDigest).
     *
     * Each must be BYTE[16] per DNG 1.7.1.0.
     */
    public function validateDngDigestTags(Ifd $ifd): void
    {
        foreach (self::DIGEST_TAGS as $tag => $name) {
            $entry = $ifd->get($tag);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if ($entry->type !== TiffConst::TYPE_BYTE || $entry->count !== 16) {
                throw new ParseError(
                    sprintf('%s must be BYTE[16], got type %d count %d.', $name, $entry->type, $entry->count),
                    1558,
                );
            }
        }
    }

    /**
     * Validates PreviewColorSpace (0xC71A) per DNG 1.7.1.0.
     *
     * Must be LONG[1] with value in 0..4 (Unknown, Gray Gamma 2.2, sRGB, Adobe RGB, ProPhoto RGB).
     */
    public function validateDngPreviewColorSpace(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::PREVIEW_COLOR_SPACE);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_LONG || $entry->count !== 1) {
            throw new ParseError(
                sprintf('PreviewColorSpace must be LONG[1], got type %d count %d.', $entry->type, $entry->count),
                1559,
            );
        }

        if (!is_int($entry->value) || $entry->value < 0 || $entry->value > 4) {
            throw new ParseError(
                sprintf('PreviewColorSpace value must be 0..4, got %d.', is_int($entry->value) ? $entry->value : -1),
                2062,
            );
        }
    }

    /**
     * Validates PreviewDateTime (0xC71B) per DNG 1.7.1.0.
     *
     * Must be ASCII with a valid ISO 8601 date/time string.
     * NUL termination is already enforced by the generic ASCII decoder.
     */
    public function validateDngPreviewDateTime(Ifd $ifd): void
    {
        $entry  = $ifd->get(DngTag::PREVIEW_DATE_TIME);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('PreviewDateTime must use ASCII type, got %d.', $entry->type),
                2063,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'PreviewDateTime must not be empty.',
                1562,
            );
        }

        // ISO 8601 basic validation: YYYY-MM-DDThh:mm:ss with optional timezone
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $entry->value, $m) !== 1) {
            throw new ParseError(
                sprintf('PreviewDateTime is not a valid ISO 8601 timestamp: %s.', $entry->value),
                2064,
            );
        }

        $month  = (int) $m[1];
        $day    = (int) $m[2];
        $hour   = (int) $m[3];
        $minute = (int) $m[4];
        $second = (int) $m[5];

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            throw new ParseError(
                sprintf('PreviewDateTime contains out-of-range date/time components: %s.', $entry->value),
                2065,
            );
        }
    }

    /**
     * Validates DNG depth enum tags per DNG 1.7.1.0.
     *
     * DepthFormat: SHORT[1], allowed {0,1,2}
     * DepthUnits: SHORT[1], allowed {0,1}
     * DepthMeasureType: SHORT[1], allowed {0,1,2}
     */
    public function validateDngDepthEnums(Ifd $ifd): void
    {
        $rules = [
            ['tag' => DngTag::DEPTH_FORMAT, 'name' => 'DepthFormat', 'allowed' => [0, 1, 2]],
            ['tag' => DngTag::DEPTH_UNITS, 'name' => 'DepthUnits', 'allowed' => [0, 1]],
            ['tag' => DngTag::DEPTH_MEASURE_TYPE, 'name' => 'DepthMeasureType', 'allowed' => [0, 1, 2]],
        ];

        foreach ($rules as $config) {
            $entry = $ifd->get($config['tag']);

            if (!$entry instanceof IfdEntry) {
                continue;
            }

            if (!is_int($entry->value) || !in_array($entry->value, $config['allowed'], true)) {
                throw new ParseError(
                    sprintf(
                        '%s value %d is out of domain per DNG 1.7.1.0.',
                        $config['name'],
                        is_int($entry->value) ? $entry->value : -1,
                    ),
                    1574,
                );
            }
        }
    }

    /**
     * Validates NoiseReductionApplied (0xC6F7) per DNG 1.7.1.0.
     *
     * Must be RATIONAL[1]. Special sentinel 0/0 means unknown.
     * Otherwise the value must be in the range [0.0, 1.0].
     */
    public function validateDngNoiseReductionApplied(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::NOISE_REDUCTION_APPLIED);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if (!$entry->value instanceof ExifRational) {
            return;
        }

        // 0/0 sentinel means unknown
        if (($entry->value->numerator === 0) && ($entry->value->denominator === 0)) {
            return;
        }

        if ($entry->value->denominator === 0) {
            throw new ParseError(
                'NoiseReductionApplied has zero denominator without 0/0 sentinel.',
                1581,
            );
        }

        $value = $entry->value->numerator / $entry->value->denominator;

        if ($value < 0.0 || $value > 1.0) {
            throw new ParseError(
                sprintf('NoiseReductionApplied must be in [0.0, 1.0], got %.4f.', $value),
                1582,
            );
        }
    }

    /**
     * Validates EnhanceParams (0xC7EE) per DNG 1.7.1.0.
     *
     * Must be ASCII type with a non-empty NUL-terminated string.
     */
    public function validateDngEnhanceParams(Ifd $ifd): void
    {
        $entry = $ifd->get(DngTag::ENHANCE_PARAMS);

        if (!$entry instanceof IfdEntry) {
            return;
        }

        if ($entry->type !== TiffConst::TYPE_ASCII) {
            throw new ParseError(
                sprintf('EnhanceParams must use ASCII type, got %d.', $entry->type),
                1575,
            );
        }

        if (!is_string($entry->value) || $entry->value === '') {
            throw new ParseError(
                'EnhanceParams must not be empty per DNG 1.7.1.0.',
                1576,
            );
        }
    }

    /**
     * Validates one DNG opcode-list payload for structural integrity.
     *
     * @param string $tagName Human-readable opcode-list tag name.
     * @param string $payload Raw opcode-list bytes.
     */
    private function validateDngOpcodeListPayload(string $tagName, string $payload): void
    {
        PayloadGuard::ensureMinimumLength($payload, 4, sprintf('%s payload', $tagName), 1635);
        $length         = strlen($payload);

        $opcodeCount    = Unpack::int('N', substr($payload, 0, 4), sprintf('%s opcode count', $tagName));
        $offset         = 4;

        $maxOpcodeCount = intdiv($length - 4, 16);

        if ($opcodeCount > $maxOpcodeCount) {
            throw new ParseError(
                sprintf(
                    '%s opcode count %d exceeds structural maximum %d for payload length %d.',
                    $tagName,
                    $opcodeCount,
                    $maxOpcodeCount,
                    $length,
                ),
                1636,
            );
        }

        for ($index = 0; $index < $opcodeCount; ++$index) {
            if (($length - $offset) < 16) {
                throw new ParseError(
                    sprintf('%s opcode %d is truncated before fixed header.', $tagName, $index),
                    1637,
                );
            }

            $paramByteCount = Unpack::int(
                'N',
                substr($payload, $offset + 12, 4),
                sprintf('%s opcode %d parameter byte count', $tagName, $index),
            );
            $offset += 16;

            if (($length - $offset) < $paramByteCount) {
                throw new ParseError(
                    sprintf(
                        '%s opcode %d declares %d parameter bytes but only %d remain.',
                        $tagName,
                        $index,
                        $paramByteCount,
                        $length - $offset,
                    ),
                    1638,
                );
            }

            $offset += $paramByteCount;
        }
    }

    /**
     * Consumes a fixed 4-byte field from OriginalRawFileData.
     */
    private function consumeDngOriginalRawFixedBlock(string $payload, int $offset, string $blockName): int
    {
        if ((strlen($payload) - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s block.', $blockName),
                1628,
            );
        }

        return $offset + 4;
    }

    /**
     * Validates one compressed-fork block in OriginalRawFileData and returns the next offset.
     *
     * @param string $payload   Raw OriginalRawFileData bytes.
     * @param int    $offset    Current parse cursor.
     * @param string $blockName Human-readable block name for error context.
     */
    private function validateDngOriginalRawForkBlock(string $payload, int $offset, string $blockName): int
    {
        $payloadLength     = strlen($payload);

        if (($payloadLength - $offset) < 4) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated before %s length field.', $blockName),
                1629,
            );
        }

        $forkStart         = $offset;
        $forkLength        = Unpack::int('N', substr($payload, $offset, 4), sprintf('%s length', $blockName));
        $offset += 4;

        if ($forkLength === 0) {
            return $offset;
        }

        $forkBlocks        = intdiv($forkLength + 65535, 65536);
        $indexCount        = $forkBlocks + 1;
        $indexBytes        = $indexCount * 4;

        if (($payloadLength - $offset) < $indexBytes) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s index table.', $blockName),
                1630,
            );
        }

        $minimumDataOffset = 4 + $indexBytes;
        $previousOffset    = -1;
        $forkDataEnd       = 0;

        for ($index = 0; $index < $indexCount; ++$index) {
            $relativeOffset = Unpack::int(
                'N',
                substr($payload, $offset + ($index * 4), 4),
                sprintf('%s index offset', $blockName),
            );

            if (($relativeOffset < $minimumDataOffset) || ($relativeOffset < $previousOffset)) {
                throw new ParseError(
                    sprintf('OriginalRawFileData has invalid %s index offsets.', $blockName),
                    1631,
                );
            }

            $previousOffset = $relativeOffset;
            $forkDataEnd    = $relativeOffset;
        }

        $forkEnd           = $forkStart + $forkDataEnd;

        if ($forkEnd > $payloadLength) {
            throw new ParseError(
                sprintf('OriginalRawFileData is truncated in %s compressed data.', $blockName),
                1632,
            );
        }

        return $forkEnd;
    }
}
