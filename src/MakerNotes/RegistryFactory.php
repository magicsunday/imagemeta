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
 * Factory that creates maker note registries with all built-in decoders registered.
 */
final class RegistryFactory
{
    /**
     * Creates a registry pre-populated with the built-in maker note decoders.
     */
    public static function createDefault(): Registry
    {
        $registry = new Registry();

        $appleDecoder = new AppleDecoder();
        $canonDecoder = new CanonDecoder();
        $nikonDecoder = new NikonDecoder();
        $sonyDecoder = new SonyDecoder();

        $registry->register('Apple', $appleDecoder);
        $registry->register('Canon', $canonDecoder);
        $registry->register('Canon Inc', $canonDecoder);
        $registry->register('Canon Inc.', $canonDecoder);
        $registry->register('Nikon', $nikonDecoder);
        $registry->register('Nikon Corporation', $nikonDecoder);
        $registry->register('Sony', $sonyDecoder);
        $registry->register('Sony Corporation', $sonyDecoder);

        return $registry;
    }
}
