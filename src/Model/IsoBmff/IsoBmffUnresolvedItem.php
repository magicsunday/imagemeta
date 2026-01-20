<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\IsoBmff;

use MagicSunday\ImageMeta\Value\Enum\ConstructionMethod;

/**
 * Describes an ISO BMFF item payload that could not be resolved.
 */
final readonly class IsoBmffUnresolvedItem
{
    /**
     * @param int                        $itemId             Item identifier.
     * @param int                        $dataReferenceIndex Data reference index used by the item.
     * @param ConstructionMethod|null    $constructionMethod Construction method declared by iloc.
     * @param IsoBmffDataReference|null  $dataReference      Parsed data reference when available.
     */
    public function __construct(
        public int $itemId,
        public int $dataReferenceIndex,
        public ?ConstructionMethod $constructionMethod,
        public ?IsoBmffDataReference $dataReference,
    ) {
    }
}
