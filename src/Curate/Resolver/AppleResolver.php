<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Value\Apple;

/**
 * Resolves Apple specific metadata from QuickTime containers.
 */
final readonly class AppleResolver
{
    /**
     * Builds an Apple value object from available metadata.
     */
    public function resolve(?QuickTimeMeta $quickTimeMeta): ?Apple
    {
        if (!$quickTimeMeta instanceof QuickTimeMeta) {
            return null;
        }

        $identifier = $quickTimeMeta->contentIdentifier();
        if ($identifier === null) {
            return null;
        }

        return new Apple($identifier);
    }
}
