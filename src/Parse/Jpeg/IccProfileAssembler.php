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
use MagicSunday\ImageMeta\Core\PayloadGuard;

use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function ksort;
use function ord;
use function range;
use function sort;
use function sprintf;
use function strlen;
use function substr;

/**
 * Assembles ICC profile data from segmented APP2 markers.
 *
 * ICC.1:2022 §B.4 defines the JPEG APP2 embedding mechanism: the profile is split into
 * numbered chunks prefixed by "ICC_PROFILE\0" followed by sequence/count bytes.
 */
final class IccProfileAssembler implements SegmentAssemblerInterface
{
    /**
     * Signature identifying ICC profile payloads inside APP2 markers.
     */
    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    /** @var list<string> */
    private array $segments = [];

    /** @var array<int, string> */
    private array $sequence = [];

    private ?int $expectedCount = null;

    private ?string $profile = null;

    /**
     * @param int $maxIccProfileSize Maximum allowed assembled ICC profile size in bytes.
     */
    public function __construct(
        private readonly int $maxIccProfileSize = 4_194_304,
    ) {
    }

    /**
     * Processes one ICC profile APP2 segment.
     *
     * @param string $payload Raw segment payload including signature.
     * @param int    $offset  Offset in the stream where the marker begins.
     *
     * @throws ParseError When the ICC segment header is invalid or inconsistent.
     */
    public function handleSegment(string $payload, int $offset): void
    {
        $signatureLength = strlen(self::ICC_SIGNATURE);
        PayloadGuard::ensureMinimumLength($payload, $signatureLength + 2, sprintf('ICC segment at offset %d', $offset), 1268);

        $sequenceNumber = ord($payload[$signatureLength]);
        $sequenceCount  = ord($payload[$signatureLength + 1]);
        $iccData        = substr($payload, $signatureLength + 2);

        $this->segments[] = $payload;

        // Sequence count must not be zero
        if ($sequenceCount === 0) {
            throw new ParseError(
                sprintf('ICC segment at offset %d has zero sequence count', $offset),
                1301,
            );
        }

        // Sequence number must be in range 1..sequenceCount
        if ($sequenceNumber === 0 || $sequenceNumber > $sequenceCount) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has out-of-range sequence number %d (expected 1..%d)',
                    $offset,
                    $sequenceNumber,
                    $sequenceCount,
                ),
                1302,
            );
        }

        // All chunks must agree on the total count
        if ($this->expectedCount === null) {
            $this->expectedCount = $sequenceCount;
        } elseif ($this->expectedCount !== $sequenceCount) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has inconsistent sequence count (%d vs %d)',
                    $offset,
                    $sequenceCount,
                    $this->expectedCount,
                ),
                1303,
            );
        }

        // Reject duplicate sequence numbers
        if (array_key_exists($sequenceNumber, $this->sequence)) {
            throw new ParseError(
                sprintf(
                    'ICC segment at offset %d has duplicate sequence number %d',
                    $offset,
                    $sequenceNumber,
                ),
                1304,
            );
        }

        $this->sequence[$sequenceNumber] = $iccData;
    }

    /**
     * Merges ordered ICC chunks into the complete profile when all segments are present.
     */
    public function finalise(): void
    {
        if (
            $this->expectedCount > 0
            && count($this->sequence) === $this->expectedCount
        ) {
            $expectedSequence = range(1, $this->expectedCount);
            $presentSequence  = array_keys($this->sequence);
            sort($presentSequence);
            if ($presentSequence === $expectedSequence) {
                ksort($this->sequence);
                $assembled = implode('', $this->sequence);

                if (strlen($assembled) > $this->maxIccProfileSize) {
                    throw new ParseError(
                        sprintf(
                            'Assembled ICC profile size %d bytes exceeds configured limit of %d bytes',
                            strlen($assembled),
                            $this->maxIccProfileSize,
                        ),
                        1964,
                    );
                }

                $this->profile = $assembled;
            }
        }
    }

    /**
     * Returns all ICC profile segments in the order encountered.
     *
     * @return list<string>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * Returns the merged ICC profile when complete metadata segments were found.
     */
    public function getProfile(): ?string
    {
        return $this->profile;
    }

    /**
     * Resets all ICC state for a fresh parse pass.
     */
    public function reset(): void
    {
        $this->segments      = [];
        $this->sequence      = [];
        $this->expectedCount = null;
        $this->profile       = null;
    }
}
