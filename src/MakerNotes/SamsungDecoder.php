<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use Closure;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\Samsung\SamsungMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Support\ReadsMakerNoteFields;
use MagicSunday\ImageMeta\Model\Tiff\TiffFieldType;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * Decoder that extracts structured metadata from Samsung maker note payloads.
 *
 * Samsung maker note tags are documented by ExifTool and stored as a TIFF-style
 * IFD payload, optionally prefixed with the ASCII string "SAMSUNG\\0".
 */
final readonly class SamsungDecoder implements MakerNotesDecoderInterface
{
    use ReadsMakerNoteFields;

    private const int TIFF_MAGIC = 0x2A;

    private const string SAMSUNG_SIGNATURE = "SAMSUNG\0";

    private const int TAG_MAKER_NOTE_VERSION = 0x0001;

    private const int TAG_DEVICE_TYPE = 0x0002;

    private const int TAG_MODEL_ID = 0x0003;

    /**
     * Creates a metadata value object describing the Samsung maker note payload.
     *
     * @param string      $raw   Raw maker note data stream captured from the image file.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier for the payload.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        $samsungData = $this->parseSamsungData($raw);

        return MakerNotesRecord::from(
            'Samsung',
            $raw,
            samsung: $samsungData,
        );
    }

    /**
     * Parses the raw Samsung maker note payload into a structured representation.
     */
    private function parseSamsungData(string $raw): ?SamsungMakerNotes
    {
        $length = strlen($raw);

        if ($length < 8) {
            return null;
        }

        $headerOffset = 0;

        if (str_starts_with($raw, self::SAMSUNG_SIGNATURE)) {
            $headerOffset = strlen(self::SAMSUNG_SIGNATURE);
        }

        if ($length < $headerOffset + 8) {
            return null;
        }

        $endian = $this->resolveEndian(substr($raw, $headerOffset, 2));

        if (!$endian instanceof Endian) {
            return null;
        }

        try {
            $magic = $this->readU16($raw, $headerOffset + 2, $endian, 'Samsung TIFF magic');

            if ($magic !== self::TIFF_MAGIC) {
                return null;
            }

            $ifdOffset = $this->readU32($raw, $headerOffset + 4, $endian, 'Samsung IFD offset');
            $ifdStart  = $headerOffset + $ifdOffset;

            if (($ifdOffset < 8) || (($ifdStart + 2) > $length)) {
                return null;
            }

            $entryCount = $this->readU16($raw, $ifdStart, $endian, 'Samsung IFD entry count');

            if ($entryCount > 10_000) {
                return null;
            }

            $entriesEnd = $ifdStart + 2 + ($entryCount * 12);

            if ($entriesEnd > $length) {
                return null;
            }

            $handlers = $this->tagHandlers($endian);
            $results  = [];

            for ($index = 0; $index < $entryCount; ++$index) {
                $entryOffset = $ifdStart + 2 + ($index * 12);
                $tag         = $this->readU16($raw, $entryOffset, $endian, 'Samsung IFD tag');
                $type        = $this->readU16($raw, $entryOffset + 2, $endian, 'Samsung IFD type');
                $count       = $this->readU32($raw, $entryOffset + 4, $endian, 'Samsung IFD count');
                $valueOffset = $this->readU32($raw, $entryOffset + 8, $endian, 'Samsung IFD value offset');

                $valueBytes = $this->resolveValueBytes(
                    $raw,
                    $headerOffset,
                    $entryOffset + 8,
                    $type,
                    $count,
                    $valueOffset,
                    $length,
                );

                if ($valueBytes === null) {
                    continue;
                }

                $handler = $handlers[$tag] ?? null;

                if ($handler !== null) {
                    $results[$tag] = $handler($valueBytes, $type);
                }
            }

            /** @var string|null $makerNoteVersion */
            $makerNoteVersion = $results[self::TAG_MAKER_NOTE_VERSION] ?? null;
            /** @var string|null $deviceType */
            $deviceType = $results[self::TAG_DEVICE_TYPE] ?? null;
            /** @var int|null $modelId */
            $modelId = $results[self::TAG_MODEL_ID] ?? null;
        } catch (ParseError) {
            // Samsung maker notes may use non-standard IFD layouts; parse failures yield null.
            return null;
        }

        if (($makerNoteVersion === null) && ($deviceType === null) && ($modelId === null)) {
            return null;
        }

        return new SamsungMakerNotes($makerNoteVersion, $deviceType, $modelId);
    }

    /**
     * Returns a tag-ID-to-parser mapping for Samsung maker note tags.
     *
     * @return array<int, Closure(string, int): (string|int|null)>
     */
    private function tagHandlers(Endian $endian): array
    {
        return [
            self::TAG_MAKER_NOTE_VERSION => fn (string $bytes, int $type): ?string => $this->parseAscii($bytes),
            self::TAG_DEVICE_TYPE        => fn (string $bytes, int $type): ?string => $this->parseAscii($bytes),
            self::TAG_MODEL_ID           => fn (string $bytes, int $type): ?int => $this->parseInt($bytes, $type, $endian),
        ];
    }

    /**
     * Resolves the endian marker for Samsung maker note payloads.
     */
    private function resolveEndian(string $endian): ?Endian
    {
        return match ($endian) {
            Endian::Little->value => Endian::Little,
            Endian::Big->value    => Endian::Big,
            default               => null,
        };
    }

    /**
     * Resolves the bytes representing a tag value.
     */
    private function resolveValueBytes(
        string $raw,
        int $headerOffset,
        int $inlineOffset,
        int $type,
        int $count,
        int $valueOffset,
        int $length,
    ): ?string {
        $typeSize = $this->typeSize($type);

        if (($typeSize === 0) || ($count < 1)) {
            return null;
        }

        if ($count > intdiv($length, $typeSize)) {
            return null;
        }

        $dataSize = $typeSize * $count;

        if ($dataSize <= 4) {
            return substr($raw, $inlineOffset, $dataSize);
        }

        $dataOffset = $headerOffset + $valueOffset;

        if (($dataOffset < 0) || (($dataOffset + $dataSize) > $length)) {
            return null;
        }

        return substr($raw, $dataOffset, $dataSize);
    }

    /**
     * Parses an integer value from the supplied bytes.
     */
    private function parseInt(string $valueBytes, int $type, Endian $endian): ?int
    {
        if ($type === TiffFieldType::Short->value) {
            if (strlen($valueBytes) < 2) {
                return null;
            }

            return Unpack::int($endian === Endian::Little ? 'v' : 'n', substr($valueBytes, 0, 2), 'Samsung SHORT');
        }

        if ($type === TiffFieldType::Long->value) {
            if (strlen($valueBytes) < 4) {
                return null;
            }

            return Unpack::int($endian === Endian::Little ? 'V' : 'N', substr($valueBytes, 0, 4), 'Samsung LONG');
        }

        return null;
    }
}
