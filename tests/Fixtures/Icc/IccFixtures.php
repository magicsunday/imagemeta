<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Fixtures\Icc;

use function pack;

/**
 * Provides synthetic ICC profile fixtures for unit tests.
 * It defines minimal profiles with required headers and a description tag.
 * The fixtures are packed from hex strings to ensure deterministic byte layouts.
 * This keeps ICC parser tests stable without large binary files.
 */
final class IccFixtures
{
    private const string MINIMAL_PROFILE_HEX = '000000f1000000004210000000000000'
        . '5247422058595a200000000000000000'
        . '00000000616373700000000000000000'
        . '00000000000000000000000000000000'
        . '00000001000000000000000000000000'
        . '0000000000112233445566778899aabb'
        . 'ccddeeff000000000000000000000000'
        . '00000000000000000000000000000000'
        . '00000001646573630000009000000061'
        . '64657363000000000000000d54657374'
        . '2050726f66696c650000000000000000'
        . '00000000000000000000000000000000'
        . '00000000000000000000000000000000'
        . '00000000000000000000000000000000'
        . '00000000000000000000000000000000'
        . '00';

    /**
     * Returns a minimal ICC profile with description and rendering intent tags.
     */
    public static function minimalProfile(): string
    {
        return pack('H*', self::MINIMAL_PROFILE_HEX);
    }
}
