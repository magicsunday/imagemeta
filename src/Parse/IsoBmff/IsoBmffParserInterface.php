<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;

/**
 * Defines the contract for extracting metadata from ISO BMFF streams.
 */
interface IsoBmffParserInterface
{
    /**
     * @return array{0:list<string>,1:list<string>,2:?QuickTimeMeta,3:?IsoBmffItemReferenceMap,4:?IsoBmffDataReferenceMap,5:list<IsoBmffUnresolvedItem>}
     *
     * @throws ParseError
     * @throws BoundsError
     */
    public function extract(): array;
}
