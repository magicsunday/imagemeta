<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use Closure;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function array_any;
use function is_finite;
use function is_float;
use function is_int;
use function sprintf;

/**
 * Resolves IFD pointer tags, SubIFD chains, and Interoperability IFD discovery.
 *
 * EXIF 3.0 §4.6.3 specifies the pointer tag semantics for Exif, GPS, and
 * Interoperability IFD chaining honoured by this traverser.
 */
final class TiffIfdTraverser
{
    /**
     * Tracks pointer offsets inspected while resolving interoperability IFDs.
     *
     * @var array<int, bool>
     */
    private array $interopVisitedOffsets = [];

    /**
     * Tracks pointer offsets inspected while resolving SubIFDs.
     *
     * @var array<int, bool>
     */
    private array $subIfdVisitedOffsets = [];

    /**
     * @param TiffOffsetValidator             $offsetValidator Offset bounds checking.
     * @param Closure(int|UInt64|string): Ifd $readIfd         Callback to read an IFD at a given offset.
     */
    public function __construct(
        private readonly TiffOffsetValidator $offsetValidator,
        private readonly Closure $readIfd,
    ) {
    }

    /**
     * Extracts a validated offset from an IFD pointer entry.
     *
     * EXIF 3.0 §4.6.3 requires pointer tags to reference additional directories
     * by absolute offsets.
     *
     * @param IfdEntry $entry Entry that should contain a pointer/offset value.
     */
    public function pointerOffset(IfdEntry $entry): ?int
    {
        // Postel's Law: skip malformed IFD pointer.
        try {
            $value = $entry->value;

            if (is_int($value)) {
                return $this->validatePointerOffset($value, $entry->tag);
            }

            if ($value instanceof UInt64) {
                if ($value->isZero()) {
                    return null;
                }

                return $this->offsetValidator->ensureOffset($value, sprintf('IFD pointer tag 0x%04X', $entry->tag));
            }

            if (is_float($value)) {
                return $this->pointerOffsetFromFloat($value, $entry->tag);
            }

            if ($value instanceof ExifNumericList) {
                $first = $value->values[0] ?? null;
                if (is_int($first)) {
                    return $this->validatePointerOffset($first, $entry->tag);
                }

                if ($first instanceof UInt64) {
                    if ($first->isZero()) {
                        return null;
                    }

                    return $this->offsetValidator->ensureOffset($first, sprintf('IFD pointer tag 0x%04X', $entry->tag));
                }

                if (is_float($first)) {
                    return $this->pointerOffsetFromFloat($first, $entry->tag);
                }
            }

            return null;
        } catch (ParseError|BoundsError) {
            return null;
        }
    }

    /**
     * Resolves SubIFD pointers (Tag 0x014A) from the given parent IFD.
     *
     * @param Ifd $parentIfd Parent IFD to search for SubIFDs.
     *
     * @return array<int, Ifd>
     */
    public function resolveSubIfds(Ifd $parentIfd): array
    {
        $entry = $parentIfd->get(TiffTag::SUB_IFDS);

        if (!$entry instanceof IfdEntry) {
            return [];
        }

        $offsets = $this->extractPointerOffsets($entry);
        $result  = [];

        foreach ($offsets as $offset) {
            if (isset($this->subIfdVisitedOffsets[$offset])) {
                continue;
            }

            $this->subIfdVisitedOffsets[$offset] = true;
            $result[$offset]                     = ($this->readIfd)($offset);
        }

        return $result;
    }

    /**
     * Attempts to resolve an interoperability IFD from the provided directories.
     *
     * EXIF 3.0 §4.6.3 specifies that the Interoperability IFD is located via the
     * pointer tag 0xA005 stored within the Exif IFD.
     *
     * @param Ifd|null ...$ifds Candidate directories to search.
     */
    public function locateInteropIfd(?Ifd ...$ifds): ?Ifd
    {
        /** @var list<Ifd|null> $queue */
        $queue = [...$ifds];

        while ($queue !== []) {
            $next  = $queue;
            $queue = [];

            foreach ($next as $ifd) {
                if (!$ifd instanceof Ifd) {
                    continue;
                }

                if ($this->ifdLooksLikeInterop($ifd)) {
                    return $ifd;
                }

                $entry = $ifd->get(ExifTag::INTEROPERABILITY_IFD_POINTER);
                if (!$entry instanceof IfdEntry) {
                    continue;
                }

                $offset = $this->pointerOffset($entry);
                if ($offset === null) {
                    continue;
                }

                if (isset($this->interopVisitedOffsets[$offset])) {
                    continue;
                }

                $this->interopVisitedOffsets[$offset] = true;

                $candidate = ($this->readIfd)($offset);

                if ($this->ifdLooksLikeInterop($candidate)) {
                    return $candidate;
                }

                $queue[] = $candidate;
            }
        }

        return null;
    }

    /**
     * Validates that an offset fits within the supported integer range.
     *
     * @param int $offset Candidate offset.
     * @param int $tag    Tag identifier emitting the offset.
     */
    private function validatePointerOffset(int $offset, int $tag): ?int
    {
        if ($offset <= 0) {
            return null;
        }

        if ($offset < 8) {
            throw new ParseError(
                sprintf('IFD pointer tag 0x%04X offset %d points into TIFF header', $tag, $offset),
                1407,
            );
        }

        return $this->offsetValidator->ensureOffset($offset, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Normalizes a floating-point offset representation to a validated integer.
     *
     * @param float $value Floating-point representation to normalize.
     * @param int   $tag   Tag identifier emitting the offset.
     */
    private function pointerOffsetFromFloat(float $value, int $tag): ?int
    {
        if (!is_finite($value) || (float) (int) $value !== $value) {
            throw new ParseError(sprintf('IFD pointer tag 0x%04X must contain an integer offset.', $tag), 1342);
        }

        if ($value <= 0.0) {
            return null;
        }

        return $this->offsetValidator->ensureOffset((int) $value, sprintf('IFD pointer tag 0x%04X', $tag));
    }

    /**
     * Extracts one or more pointer offsets from an IFD entry value.
     *
     * @param IfdEntry $entry Entry containing pointer offsets.
     *
     * @return list<int>
     */
    private function extractPointerOffsets(IfdEntry $entry): array
    {
        $value = $entry->value;

        if (is_int($value)) {
            $offset = $this->validatePointerOffset($value, $entry->tag);

            return $offset !== null ? [$offset] : [];
        }

        if ($value instanceof UInt64) {
            if ($value->isZero()) {
                return [];
            }

            return [$this->offsetValidator->ensureOffset($value, sprintf('SubIFDs tag 0x%04X', $entry->tag))];
        }

        if ($value instanceof ExifNumericList) {
            $offsets = [];
            foreach ($value->values as $component) {
                if (is_int($component)) {
                    $offset = $this->validatePointerOffset($component, $entry->tag);
                    if ($offset !== null) {
                        $offsets[] = $offset;
                    }
                } elseif ($component instanceof UInt64 && !$component->isZero()) {
                    $offsets[] = $this->offsetValidator->ensureOffset($component, sprintf('SubIFDs tag 0x%04X', $entry->tag));
                }
            }

            return $offsets;
        }

        return [];
    }

    /**
     * Determines whether the provided directory contains interoperability tags.
     *
     * @param Ifd $ifd Directory to check.
     */
    private function ifdLooksLikeInterop(Ifd $ifd): bool
    {
        $interopTags = [
            ExifTag::INTEROPERABILITY_INDEX,
        ];

        return array_any($interopTags, fn (int $tag): bool => $ifd->get($tag) instanceof IfdEntry);
    }
}
