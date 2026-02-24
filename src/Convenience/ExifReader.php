<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Convenience;

use MagicSunday\ImageMeta\Contract\TiffExifParserInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Value\StructuredMetadata;

/**
 * Facade exposing EXIF-only access for supported container formats.
 */
final readonly class ExifReader
{
    private MetadataReader $metadataReader;

    /**
     * @param TiffExifParserInterface $tiffReader Optional TIFF EXIF reader reused across calls.
     *
     * Providing a custom reader allows sharing caches or maker-notes registries in higher-level code.
     */
    public function __construct(private TiffExifParserInterface $tiffReader)
    {
        $this->metadataReader = MetadataReader::createDefault($this->tiffReader);
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
