<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Groups technical and derived metadata: colour profiles, composites, interop, integrity, TIFF, XMP, and FlashPix.
 */
final readonly class TechnicalData
{
    /**
     * @param Derived            $derived      Derived metadata.
     * @param ColorProfile       $colorProfile Colour profile metadata.
     * @param CompositeImageInfo $composite    Composite image information.
     * @param Interop            $interop      Interoperability metadata.
     * @param Integrity          $integrity    Integrity verification metadata.
     * @param TiffData           $tiff         TIFF structure metadata.
     * @param Xmp                $xmp          XMP metadata.
     * @param FlashPix           $flashPix     FlashPix metadata.
     */
    public function __construct(
        public Derived $derived,
        public ColorProfile $colorProfile,
        public CompositeImageInfo $composite,
        public Interop $interop,
        public Integrity $integrity,
        public TiffData $tiff,
        public Xmp $xmp,
        public FlashPix $flashPix,
    ) {
    }
}
