<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

/**
 * Common lifecycle contract for multi-segment JPEG assemblers.
 *
 * Each assembler collects data across multiple JPEG marker segments via
 * handleSegment() and produces its result after finalise() completes
 * any deferred assembly or validation.
 */
interface SegmentAssemblerInterface
{
    /**
     * Processes a single JPEG marker segment payload.
     *
     * @param string $payload Raw segment payload (after marker and length).
     * @param int    $offset  Byte offset of the segment within the JPEG stream.
     */
    public function handleSegment(string $payload, int $offset): void;

    /**
     * Completes assembly after all segments have been processed.
     */
    public function finalise(): void;
}
