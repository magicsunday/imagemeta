<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Value\StructuredMetadata;

/**
 * Facade exposing EXIF-only access for supported container formats.
 */
final readonly class ExifReader
{
    private MetadataReader $metadataReader;

    /**
     * @param TiffExifParser $tiffReader Optional TIFF EXIF reader reused across calls.
     *
     * Providing a custom reader allows sharing caches or maker-notes registries in higher-level
     * code while defaulting to a new reader instance when no dependency is supplied.
     */
    public function __construct(private TiffExifParser $tiffReader = new TiffExifParser())
    {
        $this->metadataReader = new MetadataReader($this->tiffReader);
    }

    /**
     * Reads EXIF metadata from JPEG and ISO-BMFF (e.g. HEIC, MOV, MP4) containers and returns the
     * curated structured aggregate composed from the parsed value objects.
     *
     * @param string $path Absolute or relative path to the media file that should be parsed.
     *
     * @return StructuredMetadata Immutable aggregate exposing the normalised metadata slices.
     */
    public function read(string $path): StructuredMetadata
    {
        return $this->metadataReader->read($path)->structured();
    }
}
