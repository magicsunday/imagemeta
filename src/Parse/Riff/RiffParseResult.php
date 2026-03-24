<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Riff;

use MagicSunday\ImageMeta\Model\Riff\RiffAviHeader;
use MagicSunday\ImageMeta\Model\Riff\RiffExifChunk;
use MagicSunday\ImageMeta\Model\Riff\RiffInfo;

/**
 * Immutable result returned by {@see RiffParserInterface::extract()}.
 */
final readonly class RiffParseResult
{
    /**
     * @param list<string>       $exifBlobs Raw TIFF/EXIF blobs extracted from strd chunks.
     * @param list<string>       $xmpBlobs  Raw XMP packets extracted from _PMX chunks.
     * @param RiffInfo|null      $info      INFO chunk metadata, if present.
     * @param RiffAviHeader|null $aviHeader Parsed AVI main header, if present.
     * @param RiffExifChunk|null $riffExif  RIFF-native EXIF sub-chunk fields, if present.
     */
    public function __construct(
        public array $exifBlobs,
        public array $xmpBlobs,
        public ?RiffInfo $info,
        public ?RiffAviHeader $aviHeader,
        public ?RiffExifChunk $riffExif,
    ) {
    }
}
