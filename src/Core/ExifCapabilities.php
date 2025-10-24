<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core;

use function preg_match;
use function sprintf;
use function trim;

/**
 * Maps raw EXIF version identifiers to capability profiles defined by the specification.
 */
final class ExifCapabilities
{
    private const DEFAULT_PROFILE = '2.2';

    /**
     * Derives the EXIF capability profile name from a raw version marker.
     */
    public static function fromVersion(?string $version): string
    {
        if ($version === null) {
            return self::DEFAULT_PROFILE;
        }

        $normalized = trim($version, "\0\t\n\r \v");
        if ($normalized === '') {
            return self::DEFAULT_PROFILE;
        }

        $map = [
            '0220' => '2.2',
            '0221' => '2.2',
            '0230' => '2.3',
            '0231' => '2.3',
            '0300' => '3.0',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        if (preg_match('/^(\d)\.(\d)(\d)$/', $normalized, $matches) === 1) {
            $candidate = sprintf('0%d%d%d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
            if (isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        if (preg_match('/^(\d)\.(\d)$/', $normalized, $matches) === 1) {
            $candidate = sprintf('0%d%d0', (int) $matches[1], (int) $matches[2]);
            if (isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        return self::DEFAULT_PROFILE;
    }
}
