<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;

/**
 * Immutable configuration values for JPEG parser guard limits.
 */
final readonly class JpegParserConfig
{
    /**
     * @param int $maxAppSegmentSize         Maximum APP payload length in bytes (JPEG 16-bit bound: 65,533).
     * @param int $extendedXmpGuidLength     Extended XMP GUID byte length.
     * @param int $flashPixMaxContentEntries Maximum allowed FlashPix contents-list entries.
     * @param int $flashPixMaxStreamSize     Maximum allowed FlashPix stream size per entry in bytes.
     * @param int $maxExtendedXmpSize        Maximum cumulative ExtendedXMP payload size in bytes.
     * @param int $maxIccProfileSize         Maximum combined ICC profile size in bytes.
     * @param int $maxFlashPixTotalSize      Maximum cumulative FlashPix stream size in bytes across all entries.
     */
    public function __construct(
        public int $maxAppSegmentSize = 65_533,
        public int $extendedXmpGuidLength = 32,
        public int $flashPixMaxContentEntries = 1024,
        public int $flashPixMaxStreamSize = 16_777_216,
        public int $maxExtendedXmpSize = 10_485_760,
        public int $maxIccProfileSize = 4_194_304,
        public int $maxFlashPixTotalSize = 8_388_608,
    ) {
        if ($this->maxAppSegmentSize < 1) {
            throw new ParseError('maxAppSegmentSize must be greater than zero.', 1854);
        }

        if ($this->extendedXmpGuidLength < 1) {
            throw new ParseError('extendedXmpGuidLength must be greater than zero.', 1855);
        }

        if ($this->flashPixMaxContentEntries < 1) {
            throw new ParseError('flashPixMaxContentEntries must be greater than zero.', 1856);
        }

        if ($this->flashPixMaxStreamSize < 1) {
            throw new ParseError('flashPixMaxStreamSize must be greater than zero.', 1857);
        }

        if ($this->maxExtendedXmpSize < 1) {
            throw new ParseError('maxExtendedXmpSize must be greater than zero.', 1943);
        }

        if ($this->maxIccProfileSize < 1) {
            throw new ParseError('maxIccProfileSize must be greater than zero.', 1944);
        }

        if ($this->maxFlashPixTotalSize < 1) {
            throw new ParseError('maxFlashPixTotalSize must be greater than zero.', 1945);
        }
    }
}
