<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reconciliation;

use DateTimeImmutable;
use Exception;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;

/**
 * Resolves XMP fallback values for EXIF tags using the mapping registry.
 *
 * Each typed accessor looks up the tag in the registry, queries the XmpDocument
 * with the mapped namespace + property, and converts the raw XMP value to the
 * expected PHP type.
 *
 * CIPA DC-X010-2017 defines the authoritative EXIF↔XMP property mappings.
 */
final readonly class XmpFallbackResolver
{
    public function __construct(
        private XmpDocument $xmpDocument,
        private ExifXmpMappingRegistry $registry = new ExifXmpMappingRegistry([], [], []),
    ) {
    }

    /**
     * Creates a resolver with the default CIPA DC-X010-2017 mapping registry.
     */
    public static function fromDocument(XmpDocument $xmpDocument): self
    {
        return new self($xmpDocument, ExifXmpMappingRegistry::createDefault());
    }

    /**
     * Resolves an integer value from XMP for the given EXIF tag.
     */
    public function int(int $exifTag): ?int
    {
        $mapping = $this->registry->findByExifTag($exifTag);

        if (!$mapping instanceof ExifXmpMapping) {
            return null;
        }

        return $this->xmpDocument->int($mapping->xmpNamespace->value, $mapping->xmpProperty);
    }

    /**
     * Resolves a float value from XMP for the given EXIF tag.
     *
     * Handles XMP rational strings like "1/125" via XmpDocument::float().
     */
    public function float(int $exifTag): ?float
    {
        $mapping = $this->registry->findByExifTag($exifTag);

        if (!$mapping instanceof ExifXmpMapping) {
            return null;
        }

        return $this->xmpDocument->float($mapping->xmpNamespace->value, $mapping->xmpProperty);
    }

    /**
     * Resolves a string value from XMP for the given EXIF tag.
     */
    public function string(int $exifTag): ?string
    {
        $mapping = $this->registry->findByExifTag($exifTag);

        if (!$mapping instanceof ExifXmpMapping) {
            return null;
        }

        return $this->xmpDocument->string($mapping->xmpNamespace->value, $mapping->xmpProperty);
    }

    /**
     * Resolves a DateTime value from XMP for the given EXIF tag.
     *
     * Parses ISO 8601 date strings from XMP into DateTimeImmutable.
     */
    public function dateTime(int $exifTag): ?DateTimeImmutable
    {
        $raw = $this->string($exifTag);

        if ($raw === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Resolves a GPS coordinate string from XMP for the given GPS IFD tag.
     */
    public function gpsString(int $gpsTag): ?string
    {
        $mapping = $this->registry->findGpsTag($gpsTag);

        if (!$mapping instanceof ExifXmpMapping) {
            return null;
        }

        return $this->xmpDocument->string($mapping->xmpNamespace->value, $mapping->xmpProperty);
    }

    /**
     * Resolves a GPS float value from XMP for the given GPS IFD tag.
     */
    public function gpsFloat(int $gpsTag): ?float
    {
        $mapping = $this->registry->findGpsTag($gpsTag);

        if (!$mapping instanceof ExifXmpMapping) {
            return null;
        }

        return $this->xmpDocument->float($mapping->xmpNamespace->value, $mapping->xmpProperty);
    }
}
