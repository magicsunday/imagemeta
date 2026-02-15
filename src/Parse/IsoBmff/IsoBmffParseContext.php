<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\IsoBmff;

use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffUnresolvedItem;

/**
 * Mutable parse-state container shared across nested ISO BMFF parsing methods.
 */
final class IsoBmffParseContext
{
    /** @var list<string> */
    public array $exifBlobs = [];

    /** @var list<string> */
    public array $xmpBlobs = [];

    /** @var array<string, string|int|float|bool> */
    public array $qtKeys = [];

    /** @var array<int, array<int, list<IsoBmffItemReference>>> */
    public array $itemReferences = [];

    /** @var array<int, array<int, IsoBmffDataReference>> */
    public array $dataReferences = [];

    /** @var list<IsoBmffUnresolvedItem> */
    public array $unresolvedItems = [];

    /** @var array<string, bool> */
    public array $xmpHashes = [];

    /** @var array<string, list<array{type: int, locale: int, value: string|int|float|bool}>> */
    public array $qtDataAtoms = [];
}
