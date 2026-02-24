<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Base interface for Apple property list values.
 */
interface ApplePlistValueInterface
{
    /**
     * Resolves the plist value in keyed-archive context through polymorphic dispatch.
     */
    public function resolveValue(KeyedArchiveUnarchiver $unarchiver): ApplePlistArray|ApplePlistDictionary|ApplePlistScalar;
}
