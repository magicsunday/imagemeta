<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value\Enum;

/**
 * ISO Base Media File Format (ISOBMFF) item location construction methods.
 *
 * ISO/IEC 14496-12 (ISO Base Media File Format) §8.11.3 defines the construction
 * method field in item location boxes ('iloc'), specifying how extents are
 * addressed. HEIF (ISO/IEC 23008-12) §6.3 extends these semantics for image items.
 *
 * This enum provides type-safe identification of the addressing mode used to locate
 * metadata and image data within QuickTime/MOV, MP4, and HEIC containers.
 */
enum ConstructionMethod: int
{
    /**
     * File offset construction (method 0).
     *
     * ISO/IEC 14496-12 §8.11.3.2 specifies that construction_method=0 indicates
     * absolute file offsets. Extents reference byte positions from the start of
     * the file when base_offset=0 and data_reference_index=0.
     *
     * Used for self-contained items (no external data references).
     */
    case FILE_OFFSET = 0;

    /**
     * idat offset construction (method 1).
     *
     * ISO/IEC 14496-12 §8.11.3.2 defines construction_method=1 as offsets relative
     * to the 'idat' box payload. The base_offset or extent offset values reference
     * positions within the item data box rather than absolute file positions.
     *
     * Commonly used in HEIF images for compact metadata storage.
     */
    case IDAT_OFFSET = 1;

    /**
     * Item offset construction (method 2).
     *
     * ISO/IEC 14496-12 §8.11.3.2 describes construction_method=2 as item-relative
     * addressing. Reserved for future use or vendor extensions in most implementations.
     */
    case ITEM_OFFSET = 2;
}
