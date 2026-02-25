<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use function implode;

/**
 * Assembles ordered ICC profile chunks into a single binary payload.
 *
 * ICC.1:2022 §B.4 defines the multi-segment embedding mechanism. Each segment
 * contributes one ordered chunk; this class concatenates them in order.
 */
final class IccProfileAssembler
{
    /** @var list<string> */
    private array $chunks = [];

    private ?string $profile = null;

    /**
     * Appends one ICC data chunk in sequence order.
     *
     * @param string $chunk Raw ICC profile chunk data (header/signature bytes already stripped).
     */
    public function addChunk(string $chunk): void
    {
        $this->chunks[] = $chunk;
    }

    /**
     * Concatenates all collected chunks into the assembled ICC profile.
     */
    public function finalise(): void
    {
        if ($this->chunks === []) {
            return;
        }

        $this->profile = implode('', $this->chunks);
    }

    /**
     * Returns the assembled ICC profile payload, or null if no chunks were added.
     */
    public function getProfile(): ?string
    {
        return $this->profile;
    }

    /**
     * Resets all state for a fresh assembly pass.
     */
    public function reset(): void
    {
        $this->chunks  = [];
        $this->profile = null;
    }
}
