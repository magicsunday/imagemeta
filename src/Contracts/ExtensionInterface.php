<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Contracts;

use MagicSunday\ImageMeta\Registry;

/**
 * Describes an extension that can register additional enrichers or decoders with the core registry.
 */
interface ExtensionInterface
{
    public function register(Registry $registry): void;
}
