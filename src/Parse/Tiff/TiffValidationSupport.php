<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function count;
use function is_float;
use function is_int;
use function sprintf;

/**
 * Shared extraction utilities for TIFF structural sub-validators.
 *
 * Provides tag component extraction and uniform BitsPerSample resolution
 * used across multiple validation domains.
 */
final readonly class TiffValidationSupport
{
    public function __construct(
        private BinaryReadAccessInterface $buffer,
    ) {
    }

    /**
     * Returns the underlying memory buffer.
     */
    public function buffer(): BinaryReadAccessInterface
    {
        return $this->buffer;
    }

    /**
     * Returns canonical strip/tile tag labels used in validation messages.
     */
    public static function countedImageDataTagName(int $tag): string
    {
        return match ($tag) {
            ExifTag::STRIP_OFFSETS     => 'StripOffsets',
            ExifTag::STRIP_BYTE_COUNTS => 'StripByteCounts',
            TiffTag::TILE_OFFSETS      => 'TileOffsets',
            TiffTag::TILE_BYTE_COUNTS  => 'TileByteCounts',
            default                    => sprintf('IFD tag 0x%04X', $tag),
        };
    }

    /**
     * Extracts numeric tag components as a float list.
     *
     * @return list<float>
     */
    public function extractNumericTagComponents(IfdEntry $entry, string $tagName): array
    {
        if (is_int($entry->value) || is_float($entry->value)) {
            return [(float) $entry->value];
        }

        if ($entry->value instanceof ExifNumericList) {
            $components = [];

            foreach ($entry->value->values as $component) {
                if (is_int($component) || is_float($component)) {
                    $components[] = (float) $component;

                    continue;
                }

                throw new ParseError(
                    sprintf('%s contains unsupported non-numeric component type.', $tagName),
                    1763,
                );
            }

            return $components;
        }

        throw new ParseError(
            sprintf('%s must decode to numeric components.', $tagName),
            1764,
        );
    }

    /**
     * Extracts integer-only tag components.
     *
     * @return list<int>
     */
    public function extractIntegerTagComponents(IfdEntry $entry, string $tagName): array
    {
        $numericComponents = $this->extractNumericTagComponents($entry, $tagName);
        $integerComponents = [];

        foreach ($numericComponents as $componentIndex => $numericComponent) {
            if ((float) (int) $numericComponent !== $numericComponent) {
                throw new ParseError(
                    sprintf(
                        '%s component %d must be an integer, got %.6F.',
                        $tagName,
                        $componentIndex,
                        $numericComponent,
                    ),
                    1762,
                );
            }

            $integerComponents[] = (int) $numericComponent;
        }

        return $integerComponents;
    }

    /**
     * Resolves a uniform BitsPerSample value from the IFD.
     *
     * All BitsPerSample components must be positive, uniform, and ≤ 16.
     * Error codes are derived from a base: base+0=missing, base+1=notInt,
     * base+2=empty, base+3=nonPositive, base+4=nonUniform, base+5=tooLarge.
     */
    public function resolveUniformBitsPerSample(Ifd $ifd, string $context, int $baseErrCode): int
    {
        $bitsEntry = $ifd->get(ExifTag::BITS_PER_SAMPLE);

        if (!$bitsEntry instanceof IfdEntry) {
            throw new ParseError(
                sprintf('%s requires BitsPerSample.', $context),
                $baseErrCode,
            );
        }

        $bitDepths            = [];
        $invalidComponentType = false;

        if (is_int($bitsEntry->value)) {
            $bitDepths[] = $bitsEntry->value;
        } elseif ($bitsEntry->value instanceof ExifNumericList) {
            foreach ($bitsEntry->value->values as $component) {
                if (!is_int($component)) {
                    $invalidComponentType = true;

                    break;
                }

                $bitDepths[] = $component;
            }
        } else {
            $invalidComponentType = true;
        }

        if ($invalidComponentType) {
            throw new ParseError(
                sprintf('BitsPerSample must decode to integer components for %s.', $context),
                $baseErrCode + 1,
            );
        }

        if ($bitDepths === []) {
            throw new ParseError(
                sprintf('BitsPerSample must provide at least one value for %s.', $context),
                $baseErrCode + 2,
            );
        }

        $uniformBitDepth = $bitDepths[0];

        foreach ($bitDepths as $index => $bitDepth) {
            if ($bitDepth <= 0) {
                throw new ParseError(
                    sprintf('BitsPerSample component %d must be >= 1 for %s.', $index, $context),
                    $baseErrCode + 3,
                );
            }

            if ($bitDepth !== $uniformBitDepth) {
                throw new ParseError(
                    sprintf(
                        '%s requires uniform BitsPerSample values; component 0=%d, component %d=%d.',
                        $context,
                        $uniformBitDepth,
                        $index,
                        $bitDepth,
                    ),
                    $baseErrCode + 4,
                );
            }
        }

        if ($uniformBitDepth > 16) {
            throw new ParseError(
                sprintf('%s does not support BitsPerSample=%d (>16).', $context, $uniformBitDepth),
                $baseErrCode + 5,
            );
        }

        return $uniformBitDepth;
    }

    /**
     * Extracts and validates a SHORT[2] tag from an IFD.
     *
     * @param int    $tag       Tag constant to extract.
     * @param string $tagName   Human-readable tag name for error messages.
     * @param int    $typeCode  Error code for type/count mismatch.
     * @param int    $countCode Error code for decoded component count mismatch.
     *
     * @return array{0: int, 1: int}|null The two integer components, or null if absent.
     */
    public function extractShortPair(
        Ifd $ifd,
        int $tag,
        string $tagName,
        int $typeCode,
        int $countCode,
    ): ?array {
        $entry = $ifd->get($tag);

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if (($entry->type !== TiffConst::TYPE_SHORT) || ($entry->count !== 2)) {
            throw new ParseError(sprintf('%s must be SHORT[2].', $tagName), $typeCode);
        }

        $components = $this->extractIntegerTagComponents($entry, $tagName);

        if (count($components) !== 2) {
            throw new ParseError(
                sprintf('%s expected 2 components, decoded %d.', $tagName, count($components)),
                $countCode,
            );
        }

        return [$components[0], $components[1]];
    }
}
