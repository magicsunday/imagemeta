<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

use MagicSunday\ImageMeta\Core\ParseError;

use function implode;
use function sprintf;
use function strlen;

/**
 * Assembles ordered ICC profile chunks into a single binary payload.
 *
 * ICC.1:2022 §B.4 defines the multi-segment embedding mechanism. Each segment
 * contributes one ordered chunk; this class concatenates them in order.
 */
final class IccProfileAssembler
{
    /**
     * Default maximum assembled ICC profile size: 16 MiB.
     *
     * Practical ICC profiles are well under 1 MiB; 16 MiB provides a
     * conservative upper bound that prevents memory exhaustion from crafted inputs.
     */
    private const int MAX_ICC_PROFILE_SIZE = 16_777_216;

    /** @var list<string> */
    private array $chunks                  = [];

    private ?string $profile               = null;

    /**
     * @param int $maxSize Maximum allowed assembled ICC profile size in bytes.
     */
    public function __construct(
        private readonly int $maxSize = self::MAX_ICC_PROFILE_SIZE,
    ) {
    }

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
     *
     * @throws ParseError When the combined chunk size exceeds the configured limit.
     */
    public function finalise(): void
    {
        if ($this->chunks === []) {
            return;
        }

        $totalSize     = 0;

        foreach ($this->chunks as $chunk) {
            $totalSize += strlen($chunk);

            if ($totalSize > $this->maxSize) {
                throw new ParseError(
                    sprintf(
                        'Assembled ICC profile size exceeds configured limit of %d bytes',
                        $this->maxSize,
                    ),
                    1966,
                );
            }
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
