<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Reconciliation;

use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;

/**
 * A single EXIF tag → XMP property mapping entry per CIPA DC-X010-2017.
 */
final readonly class ExifXmpMapping
{
    /**
     * @param int              $exifTag      EXIF tag identifier (e.g. 0x010F for Make).
     * @param XmpNamespace     $xmpNamespace XMP namespace URI for the mapped property.
     * @param string           $xmpProperty  XMP property local name (e.g. 'Make').
     * @param ExifXmpValueType $valueType    Expected value type for conversion.
     */
    public function __construct(
        public int $exifTag,
        public XmpNamespace $xmpNamespace,
        public string $xmpProperty,
        public ExifXmpValueType $valueType,
    ) {
    }
}
