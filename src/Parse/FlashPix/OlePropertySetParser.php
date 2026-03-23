<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\FlashPix;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;

use function chr;
use function mb_convert_encoding;
use function rtrim;
use function strlen;
use function substr;
use function trim;

/**
 * Parses OLE property set streams as defined in FPX Appendix A.2.
 *
 * The binary format is always little-endian (byte order marker 0xFFFE) and consists of:
 * 1. Property set header (28 bytes): byte order, format, OS version, CLSID, section count
 * 2. Format ID / offset pairs (20 bytes each): FMTID GUID + section offset
 * 3. Section: size, property count, PID/offset table, property values
 *
 * @phpstan-import-type PropertyValue from OlePropertySet
 */
final class OlePropertySetParser
{
    private const int BYTE_ORDER_LE = 0xFFFE;

    /**
     * Maximum number of properties allowed in a single section.
     */
    private const int MAX_OLE_PROPERTIES = 10_000;

    /**
     * Minimum header size: 2 (byteOrder) + 2 (format) + 4 (osVer) + 16 (clsid) + 4 (sectionCount).
     */
    private const int MIN_HEADER_SIZE = 28;

    /**
     * Section table entry size: 16 (formatId) + 4 (offset).
     */
    private const int SECTION_ENTRY_SIZE = 20;

    /**
     * Windows FILETIME epoch offset (100-nanosecond intervals from 1601-01-01 to 1970-01-01).
     */
    private const int FILETIME_EPOCH_OFFSET = 116_444_736_000_000_000;

    /**
     * Parses an OLE property set stream and returns the first section as a typed property set.
     */
    public function parse(string $raw): ?OlePropertySet
    {
        $length = strlen($raw);

        if ($length < self::MIN_HEADER_SIZE) {
            return null;
        }

        $byteOrder = $this->u16($raw, 0);

        if ($byteOrder !== self::BYTE_ORDER_LE) {
            return null;
        }

        $sectionCount = $this->u32($raw, 24);

        if (($sectionCount < 1) || ($length < self::MIN_HEADER_SIZE + self::SECTION_ENTRY_SIZE)) {
            return null;
        }

        $sectionOffset = $this->u32($raw, self::MIN_HEADER_SIZE + 16);

        if (($sectionOffset < self::MIN_HEADER_SIZE + self::SECTION_ENTRY_SIZE) || (($sectionOffset + 8) > $length)) {
            return null;
        }

        return $this->parseSection($raw, $sectionOffset, $length);
    }

    /**
     * Parses a single property section starting at the given offset.
     */
    private function parseSection(string $raw, int $sectionOffset, int $length): ?OlePropertySet
    {
        try {
            return $this->parseSectionEntries($raw, $sectionOffset, $length);
        } catch (ParseError) {
            return null;
        }
    }

    private function parseSectionEntries(string $raw, int $sectionOffset, int $length): ?OlePropertySet
    {
        $propertyCount = $this->u32($raw, $sectionOffset + 4);
        $tableEnd      = $sectionOffset + 8 + ($propertyCount * 8);

        if (($propertyCount < 1) || ($propertyCount > self::MAX_OLE_PROPERTIES) || ($tableEnd > $length)) {
            return null;
        }

        $codepage   = 1252;
        $properties = [];

        for ($index = 0; $index < $propertyCount; ++$index) {
            $entryOffset    = $sectionOffset + 8 + ($index * 8);
            $pid            = $this->u32($raw, $entryOffset);
            $propertyOffset = $sectionOffset + $this->u32($raw, $entryOffset + 4);

            if (($propertyOffset + 4) > $length) {
                continue;
            }

            $typeCode = $this->u32($raw, $propertyOffset);
            $value    = $this->readValue($raw, $propertyOffset + 4, $typeCode, $length);

            if (($pid === 1) && is_int($value)) {
                $codepage = $value;
            }

            if ($value !== null) {
                $properties[$pid] = $value;
            }
        }

        if ($properties === []) {
            return null;
        }

        return new OlePropertySet($codepage, $properties);
    }

    /**
     * Reads a typed property value from the stream.
     */
    private function readValue(string $raw, int $offset, int $typeCode, int $length): string|int|float|bool|DateTimeImmutable|null
    {
        return match ($typeCode) {
            OlePropertyType::Short->value    => $this->readShort($raw, $offset, $length),
            OlePropertyType::Long->value     => $this->readLong($raw, $offset, $length),
            OlePropertyType::Float->value    => $this->readFloat($raw, $offset, $length),
            OlePropertyType::Double->value   => $this->readDouble($raw, $offset, $length),
            OlePropertyType::Boolean->value  => $this->readBoolean($raw, $offset, $length),
            OlePropertyType::Lpstr->value    => $this->readLpstr($raw, $offset, $length),
            OlePropertyType::Lpwstr->value   => $this->readLpwstr($raw, $offset, $length),
            OlePropertyType::Filetime->value => $this->readFiletime($raw, $offset, $length),
            default                          => null,
        };
    }

    private function readShort(string $raw, int $offset, int $length): ?int
    {
        if (($offset + 2) > $length) {
            return null;
        }

        return Unpack::int('v', substr($raw, $offset, 2), 'OLE SHORT');
    }

    private function readLong(string $raw, int $offset, int $length): ?int
    {
        if (($offset + 4) > $length) {
            return null;
        }

        return Unpack::int('V', substr($raw, $offset, 4), 'OLE LONG');
    }

    private function readFloat(string $raw, int $offset, int $length): ?float
    {
        if (($offset + 4) > $length) {
            return null;
        }

        return Unpack::float('g', substr($raw, $offset, 4), 'OLE FLOAT');
    }

    private function readDouble(string $raw, int $offset, int $length): ?float
    {
        if (($offset + 8) > $length) {
            return null;
        }

        return Unpack::float('e', substr($raw, $offset, 8), 'OLE DOUBLE');
    }

    private function readBoolean(string $raw, int $offset, int $length): ?bool
    {
        if (($offset + 2) > $length) {
            return null;
        }

        return Unpack::int('v', substr($raw, $offset, 2), 'OLE BOOL') !== 0;
    }

    /**
     * Reads an ANSI string (LPSTR): uint32 size + size bytes, NUL-padded to 4-byte boundary.
     */
    private function readLpstr(string $raw, int $offset, int $length): ?string
    {
        if (($offset + 4) > $length) {
            return null;
        }

        $size = $this->u32($raw, $offset);

        if (($size < 1) || (($offset + 4 + $size) > $length)) {
            return null;
        }

        $value = substr($raw, $offset + 4, $size);
        $value = trim(rtrim($value, "\0"));

        return $value === '' ? null : $value;
    }

    /**
     * Reads a Unicode string (LPWSTR): uint32 charCount + charCount*2 bytes UTF-16LE.
     */
    private function readLpwstr(string $raw, int $offset, int $length): ?string
    {
        if (($offset + 4) > $length) {
            return null;
        }

        $charCount = $this->u32($raw, $offset);

        if (($charCount < 1) || ($charCount > 1_000_000)) {
            return null;
        }

        $byteCount = $charCount * 2;

        if (($offset + 4 + $byteCount) > $length) {
            return null;
        }

        $utf16 = substr($raw, $offset + 4, $byteCount);

        /** @var string $value */
        $value = mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
        $value = trim(rtrim($value, "\0" . chr(0)));

        return $value === '' ? null : $value;
    }

    /**
     * Reads a Windows FILETIME (64-bit, 100-nanosecond intervals since 1601-01-01).
     */
    private function readFiletime(string $raw, int $offset, int $length): ?DateTimeImmutable
    {
        if (($offset + 8) > $length) {
            return null;
        }

        $lo = $this->u32($raw, $offset);
        $hi = $this->u32($raw, $offset + 4);

        $filetime = ($hi << 32) | $lo;

        if ($filetime <= self::FILETIME_EPOCH_OFFSET) {
            return null;
        }

        $unixMicro = (int) (($filetime - self::FILETIME_EPOCH_OFFSET) / 10);
        $seconds   = (int) ($unixMicro / 1_000_000);
        $micro     = $unixMicro % 1_000_000;

        $dateTime = DateTimeImmutable::createFromFormat(
            'U u',
            $seconds . ' ' . $micro,
            new DateTimeZone('UTC'),
        );

        return $dateTime instanceof DateTimeImmutable ? $dateTime : null;
    }

    private function u16(string $raw, int $offset): int
    {
        return Unpack::int('v', substr($raw, $offset, 2), 'OLE u16');
    }

    private function u32(string $raw, int $offset): int
    {
        return Unpack::int('V', substr($raw, $offset, 4), 'OLE u32');
    }
}
