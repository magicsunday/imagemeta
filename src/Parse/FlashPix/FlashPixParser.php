<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\FlashPix;

use MagicSunday\ImageMeta\Contract\FlashPixParserInterface;
use MagicSunday\ImageMeta\Model\FlashPix\FlashPixDocument;

/**
 * Parses assembled FlashPix APP2 streams by extracting OLE property set metadata.
 */
final readonly class FlashPixParser implements FlashPixParserInterface
{
    public function __construct(
        private OlePropertySetParser $oleParser = new OlePropertySetParser(),
        private FlashPixPropertyExtractor $extractor = new FlashPixPropertyExtractor(),
    ) {
    }

    /**
     * Creates a FlashPix document model from assembled streams, extracting Summary Information
     * from the first parseable OLE property set.
     *
     * @param array<int, string> $streams Assembled FlashPix extension streams.
     */
    public function parse(array $streams): FlashPixDocument
    {
        if ($streams === []) {
            return new FlashPixDocument([]);
        }

        $summary = null;

        foreach ($streams as $stream) {
            $propertySet = $this->oleParser->parse($stream);

            if (!$propertySet instanceof OlePropertySet) {
                continue;
            }

            $summary ??= $this->extractor->extractSummaryInfo($propertySet);
        }

        return new FlashPixDocument($streams, $summary);
    }
}
