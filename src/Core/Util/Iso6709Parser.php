<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Core\Util;

use function preg_match;

/**
 * Parses ISO 6709 location strings as used in QuickTime ©xyz atoms.
 *
 * Format: ±DD.DDDD±DDD.DDDD[±DDD.DDD][/]
 */
final class Iso6709Parser
{
    private function __construct()
    {
    }

    /**
     * Parses an ISO 6709 coordinate string into latitude, longitude, and optional altitude.
     *
     * @return array{latitude: float, longitude: float, altitude: float|null}|null
     */
    public static function parse(string $input): ?array
    {
        $pattern = '/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)?\/?\s*$/';

        if (preg_match($pattern, $input, $matches) !== 1) {
            return null;
        }

        return [
            'latitude'  => (float) $matches[1],
            'longitude' => (float) $matches[2],
            'altitude'  => isset($matches[3]) ? (float) $matches[3] : null,
        ];
    }
}
