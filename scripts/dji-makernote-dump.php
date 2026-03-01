#!/usr/bin/env php
<?php

/**
 * Standalone script to read and decode DJI MakerNotes from JPEG files.
 *
 * DJI MakerNotes use a bare TIFF IFD (no TIFF header) with absolute offsets
 * into the parent EXIF stream. This script extracts the full EXIF TIFF stream,
 * locates the MakerNote tag (0x927C), and parses the IFD entries within it.
 *
 * Usage: php scripts/dji-makernote-dump.php <jpeg-file>
 */

declare(strict_types=1);

// --- helpers ----------------------------------------------------------------

function readU16(string $data, int $offset, string $bo): int
{
    return unpack($bo === 'LE' ? 'v' : 'n', $data, $offset)[1];
}

function readU32(string $data, int $offset, string $bo): int
{
    return unpack($bo === 'LE' ? 'V' : 'N', $data, $offset)[1];
}

function tiffTypeName(int $type): string
{
    return match ($type) {
        1  => 'BYTE',
        2  => 'ASCII',
        3  => 'SHORT',
        4  => 'LONG',
        5  => 'RATIONAL',
        6  => 'SBYTE',
        7  => 'UNDEFINED',
        8  => 'SSHORT',
        9  => 'SLONG',
        10 => 'SRATIONAL',
        11 => 'FLOAT',
        12 => 'DOUBLE',
        default => "UNKNOWN($type)",
    };
}

function tiffTypeSize(int $type): int
{
    return match ($type) {
        1, 2, 6, 7 => 1,
        3, 8       => 2,
        4, 9, 11   => 4,
        5, 10, 12  => 8,
        default    => 0,
    };
}

// --- JPEG APP1 extraction ---------------------------------------------------

function extractExifTiffStream(string $jpeg): ?string
{
    if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
        fprintf(STDERR, "Not a JPEG file.\n");
        return null;
    }

    $pos = 2;
    $len = strlen($jpeg);

    while ($pos < $len - 1) {
        if (ord($jpeg[$pos]) !== 0xFF) {
            fprintf(STDERR, "Invalid JPEG marker at offset %d.\n", $pos);
            return null;
        }

        $marker = ord($jpeg[$pos + 1]);

        // Skip padding bytes
        if ($marker === 0xFF) {
            $pos++;
            continue;
        }

        // SOS or EOI → stop
        if ($marker === 0xDA || $marker === 0xD9) {
            break;
        }

        if ($pos + 3 >= $len) {
            break;
        }

        $segLen = unpack('n', $jpeg, $pos + 2)[1];

        // APP1 = 0xE1
        if ($marker === 0xE1) {
            $segData = substr($jpeg, $pos + 4, $segLen - 2);

            // Check for "Exif\0\0" header
            if (str_starts_with($segData, "Exif\0\0")) {
                return substr($segData, 6); // TIFF stream starts after "Exif\0\0"
            }
        }

        $pos += 2 + $segLen;
    }

    fprintf(STDERR, "No EXIF APP1 segment found.\n");
    return null;
}

// --- TIFF IFD navigation ----------------------------------------------------

function parseTiffHeader(string $tiff): ?array
{
    if (strlen($tiff) < 8) {
        return null;
    }

    $boMarker = substr($tiff, 0, 2);
    $bo       = match ($boMarker) {
        'II'    => 'LE',
        'MM'    => 'BE',
        default => null,
    };

    if ($bo === null) {
        fprintf(STDERR, "Invalid TIFF byte order marker: %s\n", bin2hex($boMarker));
        return null;
    }

    $magic = readU16($tiff, 2, $bo);
    if ($magic !== 0x002A) {
        fprintf(STDERR, "Invalid TIFF magic: 0x%04X\n", $magic);
        return null;
    }

    $ifd0Offset = readU32($tiff, 4, $bo);

    return ['bo' => $bo, 'ifd0' => $ifd0Offset];
}

/**
 * Reads all IFD entries at the given offset and returns them.
 */
function readIfd(string $tiff, int $offset, string $bo): array
{
    $len = strlen($tiff);
    if ($offset + 2 > $len) {
        return [];
    }

    $count   = readU16($tiff, $offset, $bo);
    $entries = [];
    $pos     = $offset + 2;

    for ($i = 0; $i < $count; $i++) {
        if ($pos + 12 > $len) {
            break;
        }

        $tag   = readU16($tiff, $pos, $bo);
        $type  = readU16($tiff, $pos + 2, $bo);
        $cnt   = readU32($tiff, $pos + 4, $bo);
        $valOr = substr($tiff, $pos + 8, 4);

        $typeSize  = tiffTypeSize($type);
        $totalSize = $typeSize * $cnt;

        if ($totalSize <= 4) {
            $valueBytes = substr($valOr, 0, $totalSize);
        } else {
            $dataOffset = readU32($tiff, $pos + 8, $bo);
            if ($dataOffset + $totalSize <= $len) {
                $valueBytes = substr($tiff, $dataOffset, $totalSize);
            } else {
                $valueBytes = null;
            }
        }

        $entries[] = [
            'tag'        => $tag,
            'type'       => $type,
            'count'      => $cnt,
            'totalSize'  => $totalSize,
            'valueBytes' => $valueBytes,
            'rawValOr'   => $valOr,
        ];

        $pos += 12;
    }

    // Next IFD offset
    $nextIfd = ($pos + 4 <= $len) ? readU32($tiff, $pos, $bo) : 0;

    return ['entries' => $entries, 'nextIfd' => $nextIfd];
}

/**
 * Finds an IFD entry by tag ID.
 */
function findEntry(array $entries, int $tag): ?array
{
    foreach ($entries as $entry) {
        if ($entry['tag'] === $tag) {
            return $entry;
        }
    }

    return null;
}

// --- Known DJI MakerNote tags (from ExifTool) --------------------------------

function djiTagName(int $tag): string
{
    return match ($tag) {
        0x0001 => 'MakerNoteVersion',
        0x0003 => 'SpeedX',
        0x0004 => 'SpeedY',
        0x0005 => 'SpeedZ',
        0x0006 => 'Pitch',
        0x0007 => 'Yaw',
        0x0008 => 'Roll',
        0x0009 => 'CameraPitch',
        0x000A => 'CameraYaw',
        0x000B => 'CameraRoll',
        0x000D => 'MapDatum',
        0x000E => 'Compass',
        0x1000 => 'DJI_Unknown_0x1000',
        0x1001 => 'DJI_Unknown_0x1001',
        0x1002 => 'DJI_Unknown_0x1002',
        0x1003 => 'DJI_Unknown_0x1003',
        default => sprintf('DJI_Unknown_0x%04X', $tag),
    };
}

// --- Value formatting --------------------------------------------------------

function formatValue(array $entry, string $tiff, string $bo): string
{
    $bytes = $entry['valueBytes'];
    if ($bytes === null) {
        $absOffset = readU32($tiff, 0, $bo); // dummy
        return sprintf('(data at offset 0x%08X, %d bytes — outside TIFF stream)', 0, $entry['totalSize']);
    }

    $type  = $entry['type'];
    $count = $entry['count'];

    return match ($type) {
        2 => sprintf('"%s"', rtrim($bytes, "\0")),       // ASCII
        7 => formatUndefined($bytes, $count),             // UNDEFINED
        default => formatNumeric($bytes, $type, $count, $bo),
    };
}

function formatUndefined(string $bytes, int $count): string
{
    // Try ASCII first
    $ascii = rtrim($bytes, "\0");
    if ($ascii !== '' && ctype_print($ascii)) {
        return sprintf('"%s"', $ascii);
    }

    // Show hex dump
    if ($count <= 64) {
        return sprintf('(%d bytes) %s', $count, strtoupper(bin2hex($bytes)));
    }

    // For large blobs, show first/last bytes and attempt structure analysis
    $head = strtoupper(bin2hex(substr($bytes, 0, 32)));
    $tail = strtoupper(bin2hex(substr($bytes, -16)));

    return sprintf('(%d bytes) %s ... %s', $count, $head, $tail);
}

function formatNumeric(string $bytes, int $type, int $count, string $bo): string
{
    $values   = [];
    $typeSize = tiffTypeSize($type);

    for ($i = 0; $i < $count && $i < 20; $i++) {
        $off = $i * $typeSize;
        if ($off + $typeSize > strlen($bytes)) {
            break;
        }

        $chunk = substr($bytes, $off, $typeSize);

        $values[] = match ($type) {
            1, 6    => ord($chunk),                          // BYTE / SBYTE
            3       => readU16($chunk . "\0\0", 0, $bo),     // SHORT
            8       => unpack($bo === 'LE' ? 'v' : 'n', $chunk)[1], // SSHORT
            4       => readU32($chunk, 0, $bo),              // LONG
            9       => unpack($bo === 'LE' ? 'V' : 'N', $chunk)[1], // SLONG
            5       => sprintf('%d/%d',                      // RATIONAL
                readU32($chunk, 0, $bo),
                readU32(substr($bytes, $off + 4, 4), 0, $bo)
            ),
            10      => sprintf('%d/%d',                      // SRATIONAL
                unpack($bo === 'LE' ? 'V' : 'N', $chunk)[1],
                unpack($bo === 'LE' ? 'V' : 'N', substr($bytes, $off + 4, 4))[1]
            ),
            11      => unpack($bo === 'LE' ? 'g' : 'G', $chunk)[1], // FLOAT
            12      => unpack($bo === 'LE' ? 'e' : 'E', $chunk)[1], // DOUBLE
            default => sprintf('0x%s', strtoupper(bin2hex($chunk))),
        };
    }

    $suffix = ($count > 20) ? sprintf(' ... (+%d more)', $count - 20) : '';

    return implode(', ', array_map('strval', $values)) . $suffix;
}

// --- Protobuf decoder -------------------------------------------------------

function pbWireTypeName(int $wireType): string
{
    return match ($wireType) {
        0 => 'varint',
        1 => 'fixed64',
        2 => 'length-delimited',
        5 => 'fixed32',
        default => sprintf('wire%d', $wireType),
    };
}

/**
 * Reads a protobuf varint at the given offset.
 * Returns [value, bytesConsumed] or null on error.
 */
function pbReadVarint(string $data, int $offset): ?array
{
    $result = 0;
    $shift  = 0;
    $len    = strlen($data);

    for ($i = 0; $i < 10; $i++) {
        if ($offset + $i >= $len) {
            return null;
        }

        $byte    = ord($data[$offset + $i]);
        $result |= ($byte & 0x7F) << $shift;
        $shift  += 7;

        if (($byte & 0x80) === 0) {
            return [$result, $i + 1];
        }
    }

    return null;
}

/**
 * Parses protobuf fields from a binary blob.
 * Returns array of ['field' => int, 'wireType' => int, 'value' => mixed].
 */
function pbParseFields(string $data, int $offset = 0, ?int $end = null): array
{
    $fields = [];
    $len    = $end ?? strlen($data);

    while ($offset < $len) {
        $tagResult = pbReadVarint($data, $offset);
        if ($tagResult === null) {
            break;
        }

        [$tagByte, $consumed] = $tagResult;
        $offset += $consumed;

        $fieldNumber = $tagByte >> 3;
        $wireType    = $tagByte & 0x07;

        if ($fieldNumber === 0) {
            break;
        }

        $value = null;

        switch ($wireType) {
            case 0: // varint
                $varintResult = pbReadVarint($data, $offset);
                if ($varintResult === null) {
                    return $fields;
                }
                [$value, $consumed] = $varintResult;
                $offset += $consumed;
                break;

            case 1: // fixed64
                if ($offset + 8 > $len) {
                    return $fields;
                }
                $value   = substr($data, $offset, 8);
                $offset += 8;
                break;

            case 2: // length-delimited
                $lenResult = pbReadVarint($data, $offset);
                if ($lenResult === null) {
                    return $fields;
                }
                [$dataLen, $consumed] = $lenResult;
                $offset += $consumed;
                if ($offset + $dataLen > $len) {
                    return $fields;
                }
                $value   = substr($data, $offset, $dataLen);
                $offset += $dataLen;
                break;

            case 5: // fixed32
                if ($offset + 4 > $len) {
                    return $fields;
                }
                $value   = substr($data, $offset, 4);
                $offset += 4;
                break;

            default:
                return $fields;
        }

        $fields[] = [
            'field'    => $fieldNumber,
            'wireType' => $wireType,
            'value'    => $value,
        ];
    }

    return $fields;
}

function pbFormatValue(int $wireType, mixed $value, int $depth = 0): string
{
    $indent = str_repeat('  ', $depth);

    return match ($wireType) {
        0 => sprintf('%d', $value),
        1 => sprintf('0x%s (f64=%.6f)', bin2hex($value), unpack('e', $value)[1]),
        5 => sprintf(
            '%d (0x%s, f32=%.6f)',
            unpack('V', $value)[1],
            strtoupper(bin2hex($value)),
            unpack('g', $value)[1],
        ),
        2 => pbFormatLengthDelimited($value, $depth),
        default => sprintf('(%s)', bin2hex($value)),
    };
}

function pbFormatLengthDelimited(string $value, int $depth): string
{
    $len = strlen($value);

    // Try as UTF-8 string
    $trimmed = rtrim($value, "\0");
    if ($trimmed !== '' && mb_check_encoding($trimmed, 'UTF-8') && ctype_print($trimmed)) {
        return sprintf('"%s"', $trimmed);
    }

    // Try as nested protobuf message
    if ($len >= 2) {
        $nested = pbParseFields($value);
        if (count($nested) > 0 && pbFieldsCoverBytes($value, $nested)) {
            return pbFormatNestedMessage($nested, $depth);
        }
    }

    // Raw bytes
    if ($len <= 32) {
        return sprintf('(%d bytes) %s', $len, strtoupper(bin2hex($value)));
    }

    return sprintf('(%d bytes) %s...', $len, strtoupper(bin2hex(substr($value, 0, 32))));
}

/**
 * Checks whether parsed fields account for most of the input bytes.
 */
function pbFieldsCoverBytes(string $data, array $fields): bool
{
    if (count($fields) === 0) {
        return false;
    }

    // Verify fields look reasonable: no huge field numbers, no unknown wire types
    foreach ($fields as $f) {
        if ($f['field'] > 500) {
            return false;
        }
        if (!in_array($f['wireType'], [0, 1, 2, 5], true)) {
            return false;
        }
    }

    return count($fields) >= 2;
}

function pbFormatNestedMessage(array $fields, int $depth): string
{
    $indent = str_repeat('  ', $depth + 1);
    $lines  = ["{\n"];

    foreach ($fields as $f) {
        $lines[] = sprintf(
            "%sfield %d (%s): %s\n",
            $indent,
            $f['field'],
            pbWireTypeName($f['wireType']),
            pbFormatValue($f['wireType'], $f['value'], $depth + 1),
        );
    }

    $lines[] = str_repeat('  ', $depth) . '}';

    return implode('', $lines);
}

// --- Analyze large UNDEFINED blobs ------------------------------------------

function analyzeBlob(string $bytes, string $bo): void
{
    $len = strlen($bytes);

    echo sprintf("  Blob analysis (%d bytes):\n\n", $len);

    // Try protobuf decoding first
    $fields = pbParseFields($bytes);
    if (count($fields) >= 2 && pbFieldsCoverBytes($bytes, $fields)) {
        echo "  Detected: Protocol Buffers encoding\n\n";
        foreach ($fields as $idx => $f) {
            echo sprintf(
                "  [%d] field %d (%s): %s\n",
                $idx,
                $f['field'],
                pbWireTypeName($f['wireType']),
                pbFormatValue($f['wireType'], $f['value'], 2),
            );
        }
        echo "\n";
        return;
    }

    // Fallback: hex dump
    echo "  Hex dump (first 256 bytes):\n";
    $dumpLen = min($len, 256);
    for ($row = 0; $row < $dumpLen; $row += 16) {
        $hex = '';
        $asc = '';
        for ($col = 0; $col < 16; $col++) {
            $pos = $row + $col;
            if ($pos < $dumpLen) {
                $b    = ord($bytes[$pos]);
                $hex .= sprintf('%02X ', $b);
                $asc .= ($b >= 0x20 && $b <= 0x7E) ? chr($b) : '.';
            } else {
                $hex .= '   ';
            }
        }
        echo sprintf("  %04X: %s |%s|\n", $row, $hex, $asc);
    }

    if ($len > 256) {
        echo sprintf("  ... (%d more bytes)\n", $len - 256);
    }
}

function truncateHex(string $bytes, int $maxChars): string
{
    $hex = strtoupper(bin2hex($bytes));
    if (strlen($hex) > $maxChars) {
        return substr($hex, 0, $maxChars) . '...';
    }

    return $hex;
}

// --- Main -------------------------------------------------------------------

if ($argc < 2) {
    fprintf(STDERR, "Usage: %s <jpeg-file>\n", $argv[0]);
    exit(1);
}

$filePath = $argv[1];
if (!is_file($filePath)) {
    fprintf(STDERR, "File not found: %s\n", $filePath);
    exit(1);
}

$jpeg = file_get_contents($filePath);
if ($jpeg === false) {
    fprintf(STDERR, "Cannot read: %s\n", $filePath);
    exit(1);
}

echo sprintf("File: %s (%s bytes)\n\n", $filePath, number_format(strlen($jpeg)));

// 1. Extract EXIF TIFF stream
$tiff = extractExifTiffStream($jpeg);
if ($tiff === null) {
    exit(1);
}

echo sprintf("EXIF TIFF stream: %s bytes\n", number_format(strlen($tiff)));

// 2. Parse TIFF header
$header = parseTiffHeader($tiff);
if ($header === null) {
    fprintf(STDERR, "Cannot parse TIFF header.\n");
    exit(1);
}

$bo = $header['bo'];
echo sprintf("Byte order: %s\n", $bo);
echo sprintf("IFD0 offset: 0x%04X\n\n", $header['ifd0']);

// 3. Read IFD0
$ifd0Data = readIfd($tiff, $header['ifd0'], $bo);
if (empty($ifd0Data['entries'])) {
    fprintf(STDERR, "IFD0 is empty.\n");
    exit(1);
}

// Show Make/Model
$makeEntry  = findEntry($ifd0Data['entries'], 0x010F);
$modelEntry = findEntry($ifd0Data['entries'], 0x0110);
if ($makeEntry !== null && $makeEntry['valueBytes'] !== null) {
    echo sprintf("Make:  %s\n", rtrim($makeEntry['valueBytes'], "\0"));
}
if ($modelEntry !== null && $modelEntry['valueBytes'] !== null) {
    echo sprintf("Model: %s\n", rtrim($modelEntry['valueBytes'], "\0"));
}

// 4. Find ExifIFD pointer
$exifIfdPointer = findEntry($ifd0Data['entries'], 0x8769);
if ($exifIfdPointer === null) {
    fprintf(STDERR, "No ExifIFD pointer in IFD0.\n");
    exit(1);
}

$exifIfdOffset = readU32($exifIfdPointer['rawValOr'], 0, $bo);
echo sprintf("ExifIFD offset: 0x%04X\n\n", $exifIfdOffset);

// 5. Read ExifIFD
$exifIfdData = readIfd($tiff, $exifIfdOffset, $bo);
if (empty($exifIfdData['entries'])) {
    fprintf(STDERR, "ExifIFD is empty.\n");
    exit(1);
}

// 6. Find MakerNote tag (0x927C)
$makerNoteEntry = findEntry($exifIfdData['entries'], 0x927C);
if ($makerNoteEntry === null) {
    fprintf(STDERR, "No MakerNote tag (0x927C) in ExifIFD.\n");
    exit(1);
}

echo sprintf("MakerNote tag found:\n");
echo sprintf("  Type:  %s (%d)\n", tiffTypeName($makerNoteEntry['type']), $makerNoteEntry['type']);
echo sprintf("  Count: %d\n", $makerNoteEntry['count']);
echo sprintf("  Size:  %d bytes\n", $makerNoteEntry['totalSize']);

$makerNoteBytes = $makerNoteEntry['valueBytes'];
if ($makerNoteBytes === null) {
    fprintf(STDERR, "MakerNote data is outside TIFF stream.\n");
    exit(1);
}

echo sprintf("  Raw:   %s\n\n", strtoupper(bin2hex($makerNoteBytes)));

// 7. Parse MakerNote as bare TIFF IFD (DJI style — uses parent TIFF byte order + absolute offsets)
echo "=== DJI MakerNote IFD ===\n\n";

if (strlen($makerNoteBytes) < 2) {
    fprintf(STDERR, "MakerNote too short to contain IFD.\n");
    exit(1);
}

$mnEntryCount = readU16($makerNoteBytes, 0, $bo);
echo sprintf("IFD entry count: %d\n\n", $mnEntryCount);

$neededBytes = 2 + ($mnEntryCount * 12) + 4;
if (strlen($makerNoteBytes) < $neededBytes) {
    echo sprintf("Note: MakerNote has %d bytes, need %d for full IFD — entries may reference parent TIFF stream.\n\n",
        strlen($makerNoteBytes), $neededBytes);
}

// Parse each entry — but resolve value offsets against the parent TIFF stream (absolute offsets)
for ($i = 0; $i < $mnEntryCount; $i++) {
    $entryOff = 2 + ($i * 12);
    if ($entryOff + 12 > strlen($makerNoteBytes)) {
        echo sprintf("Entry %d: truncated\n", $i);
        break;
    }

    $tag   = readU16($makerNoteBytes, $entryOff, $bo);
    $type  = readU16($makerNoteBytes, $entryOff + 2, $bo);
    $cnt   = readU32($makerNoteBytes, $entryOff + 4, $bo);
    $valOr = substr($makerNoteBytes, $entryOff + 8, 4);

    $typeSize  = tiffTypeSize($type);
    $totalSize = $typeSize * $cnt;

    echo sprintf("Tag 0x%04X  %-20s  %-10s  count=%-6d  size=%d bytes\n",
        $tag, djiTagName($tag), tiffTypeName($type), $cnt, $totalSize);

    // Resolve value: inline (≤4 bytes) or from absolute TIFF offset
    $valueBytes = null;
    if ($totalSize <= 4) {
        $valueBytes = substr($valOr, 0, $totalSize);
        echo sprintf("  Value (inline): %s\n", formatValue([
            'type' => $type, 'count' => $cnt, 'totalSize' => $totalSize,
            'valueBytes' => $valueBytes, 'rawValOr' => $valOr,
        ], $tiff, $bo));
    } else {
        $absOffset = readU32($valOr . "\0\0\0\0", 0, $bo);
        echo sprintf("  Absolute TIFF offset: 0x%04X (%d)\n", $absOffset, $absOffset);

        if ($absOffset + $totalSize <= strlen($tiff)) {
            $valueBytes = substr($tiff, $absOffset, $totalSize);
            echo sprintf("  Value: %s\n", formatValue([
                'type' => $type, 'count' => $cnt, 'totalSize' => $totalSize,
                'valueBytes' => $valueBytes, 'rawValOr' => $valOr,
            ], $tiff, $bo));

            // Deep analysis for large UNDEFINED blobs
            if ($type === 7 && $totalSize > 64) {
                echo "\n";
                analyzeBlob($valueBytes, $bo);
            }
        } else {
            echo sprintf("  ERROR: offset 0x%04X + %d exceeds TIFF stream (%d bytes)\n",
                $absOffset, $totalSize, strlen($tiff));
        }
    }

    echo "\n";
}

// Next IFD pointer
$nextIfdOff = 2 + ($mnEntryCount * 12);
if ($nextIfdOff + 4 <= strlen($makerNoteBytes)) {
    $nextIfd = readU32($makerNoteBytes, $nextIfdOff, $bo);
    echo sprintf("Next IFD offset: 0x%08X%s\n", $nextIfd, $nextIfd === 0 ? ' (none)' : '');
}
