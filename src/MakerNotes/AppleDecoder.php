<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleJpegIfdParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextParser;

use function is_array;
use function sha1;
use function strlen;

/**
 * Decoder that extracts structured metadata from Apple maker note payloads.
 *
 * Dispatches decoding to focused sub-decoders for text plist parsing, binary plist
 * and keyed archive resolution, and value extraction/building.
 *
 * @phpstan-type NativePlistScalar bool|float|int|string|null
 * @phpstan-type NativePlistValue NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar|array<int|string, NativePlistScalar>>>>>>>
 * @phpstan-type NativePlistDictionary array<string, NativePlistValue>
 */
final readonly class AppleDecoder implements MakerNotesDecoderInterface
{
    private AppleJpegIfdParser $ifdParser;

    private PlistTextParser $textParser;

    private KeyedArchiveResolver $archiveResolver;

    private AppleMakerNotesBuilder $builder;

    public function __construct()
    {
        $this->ifdParser       = new AppleJpegIfdParser();
        $this->textParser      = new PlistTextParser();
        $this->archiveResolver = new KeyedArchiveResolver();
        $this->builder         = new AppleMakerNotesBuilder();
    }

    /**
     * Creates a metadata value object describing the Apple maker note payload.
     *
     * @param string      $raw   Raw maker note data stream.
     * @param string      $make  Reported camera make string.
     * @param string|null $model Optional camera model identifier.
     */
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        $appleData = $this->parseAppleData($raw);

        return new MakerNotesRecord(
            'Apple',
            strlen($raw),
            sha1($raw),
            $appleData
        );
    }

    /**
     * Parses the raw Apple maker note payload into a structured representation.
     *
     * @param string $raw Raw maker note data stream.
     *
     * @return AppleMakerNotes|null Parsed maker notes instance or null when the payload cannot be decoded.
     */
    private function parseAppleData(string $raw): ?AppleMakerNotes
    {
        $ifdDictionary = $this->ifdParser->parse($raw);

        if (is_array($ifdDictionary)) {
            return $this->builder->build($ifdDictionary); // @phpstan-ignore argument.type (IFD values are builder-compatible)
        }

        $decoded       = $this->archiveResolver->decodeBinaryPropertyList($raw);

        if ($decoded === null) {
            $decoded = $this->textParser->parse($raw);
        }

        if (!is_array($decoded) || !KeyedArchiveResolver::isStringKeyedDictionary($decoded)) {
            return null;
        }

        /** @var NativePlistDictionary $dictionary */
        $dictionary    = $decoded;

        $decoded       = $this->archiveResolver->resolveKeyedArchiveDictionary($dictionary);

        if ($decoded === null || !KeyedArchiveResolver::isStringKeyedDictionary($decoded)) {
            return null;
        }

        return $this->builder->build($decoded);
    }
}
