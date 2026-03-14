<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\Dji\DjiMakerNotes;
use MagicSunday\ImageMeta\Parse\Tiff\TiffFieldType;

use function rtrim;
use function sha1;
use function strlen;
use function substr;
use function trim;

/**
 * Decoder that extracts structured metadata from DJI drone maker note payloads.
 *
 * DJI maker notes use a bare TIFF IFD (no signature prefix, no own TIFF header)
 * with the parent EXIF byte order and absolute TIFF offsets. Since the decoder
 * interface does not provide the parent byte order, byte order is inferred by
 * testing both endianness variants for plausible IFD entry counts.
 */
final class DjiDecoder implements MakerNotesDecoderInterface
{
    private const int TAG_MAKER_NOTE_VERSION = 0x0001;

    private const int TAG_SPEED_X = 0x0003;

    private const int TAG_SPEED_Y = 0x0004;

    private const int TAG_SPEED_Z = 0x0005;

    private const int TAG_PITCH = 0x0006;

    private const int TAG_YAW = 0x0007;

    private const int TAG_ROLL = 0x0008;

    private const int TAG_CAMERA_PITCH = 0x0009;

    private const int TAG_CAMERA_YAW = 0x000A;

    private const int TAG_CAMERA_ROLL = 0x000B;

    private const int TAG_COMPASS = 0x000E;

    /**
     * Maximum known DJI tag ID below the vendor-specific calibration range.
     */
    private const int MAX_KNOWN_TAG = 0x000E;

    /**
     * Creates a metadata value object describing the DJI maker note payload.
     *
     * @param string      $raw   Raw maker note data stream captured from the image file.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier for the payload.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        $djiData = $this->parseDjiData($raw);

        return new MakerNotesRecord(
            'DJI',
            strlen($raw),
            sha1($raw),
            null,
            null,
            $djiData,
        );
    }

    /**
     * Parses the raw DJI maker note payload into a structured representation.
     */
    private function parseDjiData(string $raw): ?DjiMakerNotes
    {
        $length = strlen($raw);
        if ($length < 2) {
            return null;
        }

        $endian = $this->detectEndian($raw, $length);
        if (!$endian instanceof Endian) {
            return null;
        }

        try {
            $entryCount = $this->readU16($raw, 0, $endian, 'DJI IFD entry count');
            $entriesEnd = 2 + ($entryCount * 12);

            if (($entryCount < 1) || ($entriesEnd > $length)) {
                return null;
            }

            $version     = null;
            $speedX      = null;
            $speedY      = null;
            $speedZ      = null;
            $pitch       = null;
            $yaw         = null;
            $roll        = null;
            $cameraPitch = null;
            $cameraYaw   = null;
            $cameraRoll  = null;
            $compass     = null;

            for ($index = 0; $index < $entryCount; ++$index) {
                $entryOffset = 2 + ($index * 12);
                $tag         = $this->readU16($raw, $entryOffset, $endian, 'DJI IFD tag');
                $type        = $this->readU16($raw, $entryOffset + 2, $endian, 'DJI IFD type');
                $count       = $this->readU32($raw, $entryOffset + 4, $endian, 'DJI IFD count');

                if ($tag > self::MAX_KNOWN_TAG) {
                    continue;
                }

                $valueBytes = $this->resolveInlineValue($raw, $entryOffset + 8, $type, $count);
                if ($valueBytes === null) {
                    continue;
                }

                match ($tag) {
                    self::TAG_MAKER_NOTE_VERSION => $version     = $this->parseAscii($valueBytes),
                    self::TAG_SPEED_X            => $speedX      = $this->parseFloat($valueBytes, $endian),
                    self::TAG_SPEED_Y            => $speedY      = $this->parseFloat($valueBytes, $endian),
                    self::TAG_SPEED_Z            => $speedZ      = $this->parseFloat($valueBytes, $endian),
                    self::TAG_PITCH              => $pitch       = $this->parseFloat($valueBytes, $endian),
                    self::TAG_YAW                => $yaw         = $this->parseFloat($valueBytes, $endian),
                    self::TAG_ROLL               => $roll        = $this->parseFloat($valueBytes, $endian),
                    self::TAG_CAMERA_PITCH       => $cameraPitch = $this->parseFloat($valueBytes, $endian),
                    self::TAG_CAMERA_YAW         => $cameraYaw   = $this->parseFloat($valueBytes, $endian),
                    self::TAG_CAMERA_ROLL        => $cameraRoll  = $this->parseFloat($valueBytes, $endian),
                    self::TAG_COMPASS            => $compass     = $this->parseFloat($valueBytes, $endian),
                    default                      => null,
                };
            }
        } catch (ParseError) {
            return null;
        }

        if (
            ($version === null)
            && ($speedX === null)
            && ($pitch === null)
            && ($cameraPitch === null)
            && ($compass === null)
        ) {
            return null;
        }

        return new DjiMakerNotes(
            $version,
            $speedX,
            $speedY,
            $speedZ,
            $pitch,
            $yaw,
            $roll,
            $cameraPitch,
            $cameraYaw,
            $cameraRoll,
            $compass,
        );
    }

    /**
     * Infers the byte order by testing both endianness variants.
     *
     * DJI maker notes have no TIFF header, so the byte order is determined by
     * reading the IFD entry count with each endianness and checking plausibility.
     */
    private function detectEndian(string $raw, int $length): ?Endian
    {
        $le = Unpack::int('v', substr($raw, 0, 2), 'DJI endian LE');
        $be = Unpack::int('n', substr($raw, 0, 2), 'DJI endian BE');

        $leValid = ($le >= 1) && ($le <= 100) && ((2 + ($le * 12)) <= $length);
        $beValid = ($be >= 1) && ($be <= 100) && ((2 + ($be * 12)) <= $length);

        if ($leValid && !$beValid) {
            return Endian::Little;
        }

        if ($beValid && !$leValid) {
            return Endian::Big;
        }

        if ($leValid) {
            return $le <= $be ? Endian::Little : Endian::Big;
        }

        return null;
    }

    // jscpd:ignore-start

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

    // jscpd:ignore-end

    /**
     * Resolves inline value bytes for TIFF IFD entries with data size ≤ 4 bytes.
     *
     * DJI maker notes use absolute TIFF offsets for values > 4 bytes, which
     * are not resolvable from the maker note payload alone. Only inline values
     * (FLOAT count=1, SHORT count≤2, ASCII count≤4) are returned.
     */
    private function resolveInlineValue(string $raw, int $inlineOffset, int $type, int $count): ?string
    {
        $typeSize = $this->typeSize($type);
        if (($typeSize === 0) || ($count < 1)) {
            return null;
        }

        $dataSize = $typeSize * $count;
        if ($dataSize > 4) {
            return null;
        }

        if (($inlineOffset + $dataSize) > strlen($raw)) {
            return null;
        }

        return substr($raw, $inlineOffset, $dataSize);
    }

    /**
     * Returns the byte width for a TIFF type used in DJI maker notes.
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

    /**
     * Parses an ASCII-encoded value and normalizes it.
     */
    private function parseAscii(string $valueBytes): ?string
    {
        $value = trim(rtrim($valueBytes, "\0"));

        return $value === '' ? null : $value;
    }

    /**
     * Parses a single-precision IEEE 754 floating-point value.
     */
    private function parseFloat(string $valueBytes, Endian $endian): ?float
    {
        if (strlen($valueBytes) < 4) {
            return null;
        }

        return Unpack::float($endian === Endian::Little ? 'g' : 'G', $valueBytes, 'DJI FLOAT');
    }
}
