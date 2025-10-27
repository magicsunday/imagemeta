<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;


/**
 * Helper that resolves QuickTime metadata values with fallback support.
 */
final class QuickTimeLookup
{
    public function __construct(private readonly ?QuickTimeMeta $quickTime)
    {
    }

    public function string(string ...$keys): ?string
    {
        if (!$this->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTime->stringValue($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function float(string ...$keys): ?float
    {
        if (!$this->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTime->floatValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function int(string ...$keys): ?int
    {
        if (!$this->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTime->intValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function bool(string ...$keys): ?bool
    {
        if (!$this->quickTime instanceof QuickTimeMeta) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $this->quickTime->boolValue($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
