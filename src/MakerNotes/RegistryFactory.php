<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

/**
 * Factory that creates maker note registries.
 */
final class RegistryFactory
{
    public static function createDefault(): Registry
    {
        return new Registry();
    }
}
