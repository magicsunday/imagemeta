<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta;

use MagicSunday\ImageMeta\Contracts\ExtensionInterface;

/**
 * Provides global extension management for optional modules.
 */
final class Extensions
{
    private static ?Registry $registry = null;

    public static function boot(?callable $builder = null): Registry
    {
        $registry = self::registry();

        if ($builder !== null) {
            $builder($registry);
        }

        return $registry;
    }

    public static function register(ExtensionInterface $extension): void
    {
        $extension->register(self::registry());
    }

    public static function registry(): Registry
    {
        if (!self::$registry instanceof Registry) {
            self::$registry = new Registry();
        }

        return self::$registry;
    }
}
