<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use InvalidArgumentException;

/**
 * Immutable configuration values for JPEG parser guard limits.
 */
final readonly class JpegParserConfig
{
    /**
     * @param int $maxAppSegmentSize         Maximum APP payload length in bytes.
     * @param int $extendedXmpGuidLength     Extended XMP GUID byte length.
     * @param int $flashPixMaxContentEntries Maximum allowed FlashPix contents-list entries.
     * @param int $flashPixMaxStreamSize     Maximum allowed FlashPix stream size per entry in bytes.
     */
    public function __construct(
        public int $maxAppSegmentSize = 4_194_304,
        public int $extendedXmpGuidLength = 32,
        public int $flashPixMaxContentEntries = 1024,
        public int $flashPixMaxStreamSize = 16_777_216,
    ) {
        if ($this->maxAppSegmentSize < 1) {
            throw new InvalidArgumentException('maxAppSegmentSize must be greater than zero.');
        }

        if ($this->extendedXmpGuidLength < 1) {
            throw new InvalidArgumentException('extendedXmpGuidLength must be greater than zero.');
        }

        if ($this->flashPixMaxContentEntries < 1) {
            throw new InvalidArgumentException('flashPixMaxContentEntries must be greater than zero.');
        }

        if ($this->flashPixMaxStreamSize < 1) {
            throw new InvalidArgumentException('flashPixMaxStreamSize must be greater than zero.');
        }
    }
}
