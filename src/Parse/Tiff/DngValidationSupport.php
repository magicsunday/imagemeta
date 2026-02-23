<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifRationalList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

use function count;
use function implode;
use function in_array;
use function intdiv;
use function is_int;
use function sprintf;

/**
 * Shared binary I/O and extraction utilities used by multiple DNG sub-validators.
 *
 * This class holds low-level unpack helpers, version-tuple extraction, rectangle
 * parsing, and other reusable building blocks that several focused DNG validator
 * classes depend on.
 */
final readonly class DngValidationSupport
{
    /**
     * Maximum DNG backward version this parser supports.
     *
     * @var list<int>
     */
    public const array SUPPORTED_DNG_VERSION = [1, 7, 1, 0];

    public function __construct(
        private Endian $bo,
        private MemoryBuffer $buffer,
    ) {
    }

    /**
     * Returns the underlying binary buffer for direct seek/read operations.
     */
    public function buffer(): MemoryBuffer
    {
        return $this->buffer;
    }

    /**
     * Unpacks an unsigned 16-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackU16(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'v' : 'n';

        return Unpack::int($format, $b, '16-bit value from TIFF bytes');
    }

    /**
     * Unpacks an unsigned 32-bit integer from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackU32(string $b): int
    {
        $format = $this->bo === Endian::Little ? 'V' : 'N';

        return Unpack::int($format, $b, '32-bit value from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 single-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackFloat(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'g' : 'G';

        return Unpack::float($format, $b, '32-bit float from TIFF bytes');
    }

    /**
     * Unpacks an IEEE-754 double-precision float from a byte string.
     *
     * @param string $b Source bytes.
     */
    public function unpackDouble(string $b): float
    {
        $format = $this->bo === Endian::Little ? 'e' : 'E';

        return Unpack::float($format, $b, '64-bit float from TIFF bytes');
    }

    /**
     * Extracts a 4-byte DNG version tuple from the given IFD entry.
     *
     * @return list<int>|null
     */
    public function extractDngVersionTuple(Ifd $ifd, int $tag): ?array
    {
        $entry = $ifd->get($tag);
        if (!$entry instanceof IfdEntry) {
            return null;
        }

        $value = $entry->value;
        if (!$value instanceof ExifNumericList || count($value->values) !== 4) {
            return null;
        }

        $tuple = [];
        foreach ($value->values as $c) {
            if (!is_int($c)) {
                return null;
            }

            $tuple[] = $c;
        }

        return $tuple;
    }

    /**
     * Returns the effective DNG backward version for validation.
     *
     * If the explicit DNGBackwardVersion tag is present, its tuple is returned.
     * Otherwise, per DNG 1.7.1.0 §2 the default is derived from DNGVersion
     * as [major, minor, 0, 0].
     *
     * @return list<int>|null Four-element version tuple, or null when DNGVersion is absent.
     */
    public function getEffectiveDngBackwardVersion(Ifd $ifd): ?array
    {
        $explicit = $this->extractDngVersionTuple($ifd, DngTag::DNG_BACKWARD_VERSION);

        if ($explicit !== null) {
            return $explicit;
        }

        $dngVer = $this->extractDngVersionTuple($ifd, DngTag::DNG_VERSION);

        if ($dngVer === null) {
            return null;
        }

        return [$dngVer[0], $dngVer[1], 0, 0];
    }

    /**
     * Returns true if version tuple $a is strictly less than $b.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    public function dngVersionLessThan(array $a, array $b): bool
    {
        for ($i = 0; $i < 4; ++$i) {
            if ($a[$i] < $b[$i]) {
                return true;
            }

            if ($a[$i] > $b[$i]) {
                return false;
            }
        }

        return false;
    }

    /**
     * Resolves DNG ColorPlanes from available in-IFD metadata.
     *
     * DNG 1.7.1.0 defines ColorPlanes as the number of color components.
     * This parser resolves it from CfaPlaneColor count first, then
     * SamplesPerPixel when available.
     */
    public function resolveDngColorPlanes(Ifd $ifd): ?int
    {
        $cfaEntry = $ifd->get(DngTag::CFA_PLANE_COLOR);

        if (($cfaEntry instanceof IfdEntry) && ($cfaEntry->count > 0)) {
            return $cfaEntry->count;
        }

        $samplesPerPixel = $ifd->get(ExifTag::SAMPLES_PER_PIXEL);

        if (($samplesPerPixel instanceof IfdEntry) && is_int($samplesPerPixel->value) && ($samplesPerPixel->value > 0)) {
            return $samplesPerPixel->value;
        }

        return null;
    }

    /**
     * Decodes a tag payload into rectangles (top, left, bottom, right).
     *
     * @return list<array{top: int, left: int, bottom: int, right: int}>
     */
    public function extractDngRectangles(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList) {
            throw new ParseError(
                sprintf('%s must decode to a numeric list payload.', $tagName),
                1608,
            );
        }

        $values = [];
        foreach ($entry->value->values as $index => $component) {
            if ($component instanceof UInt64) {
                $values[] = $component->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($component)) {
                $values[] = $component;
            } else {
                if ((float) (int) $component !== $component) {
                    throw new ParseError(
                        sprintf('%s contains a non-integer rectangle component at index %d.', $tagName, $index),
                        1609,
                    );
                }

                $values[] = (int) $component;
            }
        }

        if (count($values) !== $entry->count) {
            throw new ParseError(
                sprintf('%s decoded component count mismatch (expected %d).', $tagName, $entry->count),
                1610,
            );
        }

        if (count($values) % 4 !== 0) {
            throw new ParseError(
                sprintf('%s must contain 4 components per rectangle.', $tagName),
                1611,
            );
        }

        $rectangles = [];
        $counter    = count($values);

        for ($index = 0; $index < $counter; $index += 4) {
            $top    = $values[$index];
            $left   = $values[$index + 1];
            $bottom = $values[$index + 2];
            $right  = $values[$index + 3];

            if (($top < 0) || ($left < 0) || ($bottom < 0) || ($right < 0)) {
                throw new ParseError(
                    sprintf('%s rectangle %d contains negative coordinates.', $tagName, intdiv($index, 4)),
                    1612,
                );
            }

            if (($top >= $bottom) || ($left >= $right)) {
                throw new ParseError(
                    sprintf(
                        '%s rectangle %d must satisfy top < bottom and left < right, got (%d,%d,%d,%d).',
                        $tagName,
                        intdiv($index, 4),
                        $top,
                        $left,
                        $bottom,
                        $right,
                    ),
                    1613,
                );
            }

            $rectangles[] = [
                'top'    => $top,
                'left'   => $left,
                'bottom' => $bottom,
                'right'  => $right,
            ];
        }

        return $rectangles;
    }

    /**
     * Returns true when two rectangles overlap with positive area.
     *
     * @param array{top: int, left: int, bottom: int, right: int} $leftRectangle
     * @param array{top: int, left: int, bottom: int, right: int} $rightRectangle
     */
    public function dngRectanglesOverlap(array $leftRectangle, array $rightRectangle): bool
    {
        return ($leftRectangle['top'] < $rightRectangle['bottom'])
            && ($rightRectangle['top'] < $leftRectangle['bottom'])
            && ($leftRectangle['left'] < $rightRectangle['right'])
            && ($rightRectangle['left'] < $leftRectangle['right']);
    }

    /**
     * Extracts two strictly positive integer values from a numeric list payload.
     *
     * @return array{0: int, 1: int}
     */
    public function extractDngPositivePairFromNumericList(IfdEntry $entry, string $tagName): array
    {
        if (!$entry->value instanceof ExifNumericList || count($entry->value->values) !== 2) {
            throw new ParseError(
                sprintf('%s must decode to exactly two numeric components.', $tagName),
                1623,
            );
        }

        $components = [];
        foreach ($entry->value->values as $index => $value) {
            if ($value instanceof UInt64) {
                $components[] = $value->toInt(sprintf('%s component %d', $tagName, $index));
            } elseif (is_int($value)) {
                $components[] = $value;
            } else {
                if ((float) (int) $value !== $value) {
                    throw new ParseError(
                        sprintf('%s component %d must be an integer value.', $tagName, $index),
                        1624,
                    );
                }

                $components[] = (int) $value;
            }
        }

        if (($components[0] <= 0) || ($components[1] <= 0)) {
            throw new ParseError(
                sprintf('%s components must be > 0, got (%d, %d).', $tagName, $components[0], $components[1]),
                1625,
            );
        }

        return [$components[0], $components[1]];
    }

    /**
     * Extracts two numeric components from crop/scale DNG tags.
     *
     * @return array{0: float, 1: float}
     */
    public function extractDngCropScalePair(IfdEntry $entry, string $tagName): array
    {
        $value = $entry->value;

        if ($value instanceof ExifRationalList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $rational) {
                if ($rational->denominator <= 0) {
                    throw new ParseError(
                        sprintf('%s rational components must have denominator > 0.', $tagName),
                        1603,
                    );
                }

                $values[] = $rational->numerator / $rational->denominator;
            }

            return [$values[0], $values[1]];
        }

        if ($value instanceof ExifNumericList) {
            if (count($value->values) !== 2) {
                throw new ParseError(
                    sprintf('%s must decode to exactly two components.', $tagName),
                    1602,
                );
            }

            $values = [];
            foreach ($value->values as $component) {
                if ($component instanceof UInt64) {
                    $values[] = (float) $component->toInt(sprintf('%s component', $tagName));
                } else {
                    $values[] = (float) $component;
                }
            }

            return [$values[0], $values[1]];
        }

        throw new ParseError(
            sprintf('%s must decode to a two-component numeric payload.', $tagName),
            1604,
        );
    }

    /**
     * Extracts and validates one optional original proxy-size tag.
     *
     * @param list<int> $allowedTypes Allowed TIFF types for this tag.
     *
     * @return array{0: float, 1: float}|null
     */
    public function extractDngOriginalProxySize(
        Ifd $ifd,
        int $tag,
        string $tagName,
        array $allowedTypes,
    ): ?array {
        $entry = $ifd->get($tag);

        if (!$entry instanceof IfdEntry) {
            return null;
        }

        if (!in_array($entry->type, $allowedTypes, true) || ($entry->count !== 2)) {
            throw new ParseError(
                sprintf(
                    '%s must use %s with count 2, got type %d count %d.',
                    $tagName,
                    $this->describeAllowedTiffTypes($allowedTypes),
                    $entry->type,
                    $entry->count,
                ),
                1639,
            );
        }

        [$width, $length] = $this->extractDngCropScalePair($entry, $tagName);
        if (($width <= 0.0) || ($length <= 0.0)) {
            throw new ParseError(
                sprintf(
                    '%s components must be > 0, got (%.6F, %.6F).',
                    $tagName,
                    $width,
                    $length,
                ),
                1640,
            );
        }

        return [$width, $length];
    }

    /**
     * Builds a human-readable TIFF type list for validation errors.
     *
     * @param list<int> $types Allowed TIFF type identifiers.
     */
    public function describeAllowedTiffTypes(array $types): string
    {
        $names = [];
        foreach ($types as $type) {
            $names[] = match ($type) {
                TiffConst::TYPE_SHORT    => 'SHORT',
                TiffConst::TYPE_LONG     => 'LONG',
                TiffConst::TYPE_RATIONAL => 'RATIONAL',
                default                  => (string) $type,
            };
        }

        return implode('|', $names);
    }
}
