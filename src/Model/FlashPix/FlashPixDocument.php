<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\FlashPix;

/**
 * Represents parsed FlashPix extension streams extracted from APP2 segments.
 */
final readonly class FlashPixDocument
{
    /**
     * Creates a FlashPix document model from assembled streams.
     *
     * @param array<int, string>       $streams     Concatenated FlashPix extension streams keyed by FPXR contents-list index.
     * @param FlashPixSummaryData|null $summaryData Extracted OLE Summary Information properties.
     */
    public function __construct(
        public array $streams,
        public ?FlashPixSummaryData $summaryData = null,
    ) {
    }
}
