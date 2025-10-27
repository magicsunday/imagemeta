<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;

/**
 * @deprecated Use {@see AppleMakerNotes} instead.
 */
if (!class_exists(__NAMESPACE__ . '\\Apple', false)) {
    class_alias(AppleMakerNotes::class, __NAMESPACE__ . '\\Apple');
}
