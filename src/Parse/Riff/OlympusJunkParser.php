<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;

use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Parses Olympus camera metadata from RIFF/AVI JUNK chunks.
 *
 * Olympus cameras embed metadata in JUNK chunks with an 'OLYMDigital Camera' signature
 * at the start. Fields are at fixed byte offsets within the payload; all string fields
 * are 24 bytes wide, null/LF-terminated. FNumber is a rational64u (2x u32 LE).
 *
 * Reference: ExifTool Olympus.pm — %Image::ExifTool::Olympus::AVI tag table.
 */
final class OlympusJunkParser
{
    /**
     * Signature at the start of an Olympus JUNK chunk payload.
     */
    public const string SIGNATURE = 'OLYMDigital Camera';

    /**
     * Minimum payload size required to extract all known fields (0xB5 = 181 bytes).
     */
    public const int MIN_PAYLOAD_SIZE = 0xB5;

    /**
     * Width of each string field in bytes.
     */
    private const int STRING_FIELD_WIDTH = 24;

    // Fixed byte offsets for known fields
    private const int OFFSET_MAKE = 0x0012;

    private const int OFFSET_MODEL = 0x002C;

    private const int OFFSET_FNUMBER = 0x005E;

    private const int OFFSET_DATE_TIME_1 = 0x0083;

    private const int OFFSET_DATE_TIME_2 = 0x009D;

    /**
     * Parses a raw JUNK chunk payload into an OlympusCameraTags value object.
     *
     * Returns null when the payload does not carry the Olympus signature or is
     * too short to contain all known fields.
     */
    public function parse(string $payload): ?OlympusCameraTags
    {
        if (strlen($payload) < self::MIN_PAYLOAD_SIZE) {
            return null;
        }

        if (!str_starts_with($payload, self::SIGNATURE)) {
            return null;
        }

        $entries = [];

        $make = $this->readString($payload, self::OFFSET_MAKE);

        if ($make !== null) {
            $entries[self::OFFSET_MAKE] = $make;
        }

        $model = $this->readString($payload, self::OFFSET_MODEL);

        if ($model !== null) {
            $entries[self::OFFSET_MODEL] = $model;
        }

        [$fNumber, $fNumberDisplay] = $this->readRational($payload, self::OFFSET_FNUMBER);

        if ($fNumberDisplay !== null) {
            $entries[self::OFFSET_FNUMBER] = $fNumberDisplay;
        }

        $dateTime1 = $this->readString($payload, self::OFFSET_DATE_TIME_1);

        if ($dateTime1 !== null) {
            $entries[self::OFFSET_DATE_TIME_1] = $dateTime1;
        }

        $dateTime2 = $this->readString($payload, self::OFFSET_DATE_TIME_2);

        if ($dateTime2 !== null) {
            $entries[self::OFFSET_DATE_TIME_2] = $dateTime2;
        }

        if ($entries === []) {
            return null;
        }

        return new OlympusCameraTags(
            $entries,
            $make,
            $model,
            $fNumber,
            $dateTime1,
            $dateTime2,
        );
    }

    /**
     * Reads a 24-byte null/LF-terminated string field at the given offset.
     */
    private function readString(string $payload, int $offset): ?string
    {
        $raw = substr($payload, $offset, self::STRING_FIELD_WIDTH);
        $str = rtrim($raw, "\x00\x0a");

        return $str !== '' ? $str : null;
    }

    /**
     * Reads a rational64u (2x u32 LE) at the given offset.
     *
     * @return array{float|null, string|null} [typed value, display string]
     */
    private function readRational(string $payload, int $offset): array
    {
        $num = Unpack::int('V', substr($payload, $offset, 4), 'olympus rational num');
        $den = Unpack::int('V', substr($payload, $offset + 4, 4), 'olympus rational den');

        if ($den === 0) {
            return [null, null];
        }

        $value   = $num / $den;
        $display = sprintf('%g', $value);

        return [$value, $display];
    }
}
