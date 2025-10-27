<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Structured;

use MagicSunday\ImageMeta\Value\ProcessingSettings;
use MagicSunday\ImageMeta\Value\WhiteBalanceDetails;

/**
 * Represents post processing and white balance related metadata.
 */
final readonly class ProcessingMetadata
{
    public function __construct(
        public ProcessingSettings $settings,
        public WhiteBalanceDetails $whiteBalance,
    ) {
    }
}
