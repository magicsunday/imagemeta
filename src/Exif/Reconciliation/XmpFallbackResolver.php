<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reconciliation;

use BackedEnum;
use MagicSunday\ImageMeta\Model\Metadata;
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
        private ExifXmpMappingRegistry $registry = new ExifXmpMappingRegistry([]),
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
     * Creates a resolver from the metadata container's XMP document.
     *
     * Returns null when no XMP document is available.
     */
    public static function fromMetadata(Metadata $metadata): ?self
    {
        $xmpDocument = $metadata->selectiveXmpDocument();

        return $xmpDocument instanceof XmpDocument ? self::fromDocument($xmpDocument) : null;
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
     * Resolves a backed enum value from XMP for the given EXIF tag.
     *
     * @template T of \BackedEnum
     *
     * @param int             $exifTag   EXIF tag identifier.
     * @param class-string<T> $enumClass Fully qualified enum class name.
     *
     * @return T|null Resolved enum case or null when absent or unmapped.
     */
    public function enum(int $exifTag, string $enumClass): ?BackedEnum
    {
        $value = $this->int($exifTag);

        if ($value === null) {
            return null;
        }

        return $enumClass::tryFrom($value);
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
}
