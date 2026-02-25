<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;

use function array_any;
use function array_key_exists;
use function array_keys;
use function count;
use function in_array;
use function min;
use function ord;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Validates the structural integrity of a JPEG frame: SOF parsing, SOS header
 * consistency, EOI termination, and mandatory pre-scan marker presence.
 *
 * EXIF 3.0 §4.7 (Table 2) defines the marker flow requirements checked here.
 */
final class JpegFrameValidator
{
    private ?int $frameMarker = null;

    private ?int $frameBitsPerSample = null;

    private ?int $frameLines = null;

    private ?int $frameSamplesPerLine = null;

    /** @var array<int, array{horizontal: int, vertical: int}>|null */
    private ?array $frameComponentSampling = null;

    /** @var array{0:int,1:int}|null */
    private ?array $frameYCbCrSubSampling = null;

    /**
     * @param JpegMarkerScanner $scanner Low-level marker I/O dependency.
     */
    public function __construct(
        private readonly JpegMarkerScanner $scanner,
    ) {
    }

    /**
     * Resets all frame state for a fresh parse pass.
     */
    public function reset(): void
    {
        $this->frameMarker            = null;
        $this->frameBitsPerSample     = null;
        $this->frameLines             = null;
        $this->frameSamplesPerLine    = null;
        $this->frameComponentSampling = null;
        $this->frameYCbCrSubSampling  = null;
    }

    public function getFrameBitsPerSample(): ?int
    {
        return $this->frameBitsPerSample;
    }

    public function getFrameLines(): ?int
    {
        return $this->frameLines;
    }

    public function getFrameSamplesPerLine(): ?int
    {
        return $this->frameSamplesPerLine;
    }

    /**
     * @return array<int, array{horizontal:int, vertical:int}>|null
     */
    public function getFrameComponentSampling(): ?array
    {
        return $this->frameComponentSampling;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function getFrameYCbCrSubSampling(): ?array
    {
        return $this->frameYCbCrSubSampling;
    }

    /**
     * Parses start of frame markers to obtain sampling information.
     *
     * @param int    $marker  Marker code (SOF0).
     * @param string $payload Raw SOF payload excluding the marker and length field.
     * @param int    $offset  Offset where the SOF marker begins.
     */
    public function handleStartOfFrame(int $marker, string $payload, int $offset): void
    {
        if ($this->frameBitsPerSample !== null) {
            return;
        }

        PayloadGuard::ensureMinimumLength($payload, 6, sprintf('SOF marker 0x%02X at offset %d', $marker, $offset), 1283);

        $componentCount = ord($payload[5]);
        if ($componentCount === 0) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d reports zero components', $marker, $offset), 1284);
        }

        $expectedLength = 6 + ($componentCount * 3);
        PayloadGuard::ensureMinimumLength($payload, $expectedLength, sprintf('SOF marker 0x%02X at offset %d', $marker, $offset), 1285);

        $components = [];
        $index      = 6;

        for ($i = 0; $i < $componentCount; ++$i) {
            $componentId     = ord($payload[$index]);
            $samplingFactors = ord($payload[$index + 1]);
            $horizontal      = $samplingFactors >> 4;
            $vertical        = $samplingFactors & BitMask::LOW_NIBBLE;

            if (array_key_exists($componentId, $components)) {
                throw new ParseError(
                    sprintf(
                        'SOF marker 0x%02X at offset %d contains duplicate component identifier %d',
                        $marker,
                        $offset,
                        $componentId,
                    ),
                    1500,
                );
            }

            if ($horizontal === 0 || $vertical === 0) {
                throw new ParseError(
                    sprintf('SOF marker 0x%02X at offset %d contains zero sampling factor', $marker, $offset),
                    1286,
                );
            }

            $components[$componentId] = [
                'horizontal' => $horizontal,
                'vertical'   => $vertical,
            ];

            $index += 3;
        }

        $fields = @unpack('nlines/nsamples', substr($payload, 1, 4));

        if (($fields === false) || !isset($fields['lines'], $fields['samples'])) {
            throw new ParseError(sprintf('SOF marker 0x%02X at offset %d has invalid dimensions', $marker, $offset), 1287);
        }

        /** @var array{lines:int,samples:int} $fields */
        if ($this->frameLines === null) {
            $this->frameLines = $fields['lines'];
        }

        if ($this->frameSamplesPerLine === null) {
            $this->frameSamplesPerLine = $fields['samples'];
        }

        $bitsPerSample = ord($payload[0]);

        $derivedSubSampling = $this->deriveYCbCrSubSampling($components);

        $this->frameMarker            = $marker;
        $this->frameBitsPerSample     = $bitsPerSample;
        $this->frameComponentSampling = $components;
        $this->frameYCbCrSubSampling  = $derivedSubSampling;
    }

    /**
     * Validates SOS header structure and SOF component consistency.
     *
     * EXIF 3.0 §4.7 requires an SOS marker segment whose component selectors
     * align with the previously declared SOF frame components.
     *
     * @param string $payload   Raw SOS header payload (without marker and length field).
     * @param int    $sosOffset Offset where the SOS marker starts.
     */
    public function validateSosHeader(string $payload, int $sosOffset): void
    {
        $payloadLength = strlen($payload);
        if ($payloadLength < 6) {
            throw new ParseError(
                sprintf('SOS marker at offset %d is too short', $sosOffset),
                1498,
            );
        }

        $componentCount = ord($payload[0]);
        if ($componentCount === 0) {
            throw new ParseError(
                sprintf('SOS marker at offset %d declares zero components', $sosOffset),
                1498,
            );
        }

        $expectedLength = 1 + ($componentCount * 2) + 3;
        if ($payloadLength !== $expectedLength) {
            throw new ParseError(
                sprintf(
                    'SOS marker at offset %d declares %d components but payload length is %d bytes (expected %d)',
                    $sosOffset,
                    $componentCount,
                    $payloadLength,
                    $expectedLength,
                ),
                1498,
            );
        }

        if ($this->frameComponentSampling === null) {
            return;
        }

        $frameComponentIds   = array_keys($this->frameComponentSampling);
        $frameComponentCount = count($frameComponentIds);

        // ITU-T T.81 §B.2.3, §G.1.2 — progressive (SOF2) scans may encode a
        // subset of frame components; non-progressive scans require all of them.
        $isProgressive = $this->frameMarker === Marker::SOF2;

        if ($isProgressive && $componentCount > $frameComponentCount) {
            throw new ParseError(
                sprintf(
                    'SOS marker at offset %d has component count %d exceeding SOF component count %d',
                    $sosOffset,
                    $componentCount,
                    $frameComponentCount,
                ),
                1497,
            );
        }

        if (!$isProgressive && $componentCount !== $frameComponentCount) {
            throw new ParseError(
                sprintf(
                    'SOS marker at offset %d has component count %d but SOF declares component count %d',
                    $sosOffset,
                    $componentCount,
                    $frameComponentCount,
                ),
                1497,
            );
        }

        $seenSelectors = [];
        $index         = 1;

        for ($i = 0; $i < $componentCount; ++$i) {
            $componentSelector = ord($payload[$index]);
            if (isset($seenSelectors[$componentSelector])) {
                throw new ParseError(
                    sprintf(
                        'SOS marker at offset %d contains duplicate component selector %d',
                        $sosOffset,
                        $componentSelector,
                    ),
                    1496,
                );
            }

            if (!array_key_exists($componentSelector, $this->frameComponentSampling)) {
                throw new ParseError(
                    sprintf(
                        'SOS marker at offset %d references unknown component selector %d not declared in SOF',
                        $sosOffset,
                        $componentSelector,
                    ),
                    1495,
                );
            }

            $seenSelectors[$componentSelector] = true;
            $index += 2;
        }
    }

    /**
     * Validates the SOS segment header for structural correctness.
     *
     * EXIF 3.0 §4.7.1 requires all metadata APP segments to appear before the
     * first SOS marker.  Once SOS is reached, there is nothing left to extract.
     * The entropy-coded scan data following the SOS header is intentionally not
     * scanned — doing so would require an O(n) byte-by-byte traversal of the
     * entire image payload with no metadata benefit.
     *
     * @param int $sosOffset Offset where the SOS marker starts.
     */
    public function validateSosSegment(int $sosOffset): void
    {
        $scanHeaderLength  = $this->scanner->readSegmentLength(Marker::SOS, $sosOffset, false);
        $scanHeaderPayload = $this->scanner->readSegmentPayload(Marker::SOS, $sosOffset, $scanHeaderLength - 2);
        $this->validateSosHeader($scanHeaderPayload, $sosOffset);
    }

    /**
     * Determines whether the marker is one of the JPEG Start Of Frame marker codes.
     */
    public function isStartOfFrameMarker(int $marker): bool
    {
        return in_array(
            $marker,
            [
                Marker::SOF0,
                Marker::SOF1,
                Marker::SOF2,
                Marker::SOF3,
                Marker::SOF5,
                Marker::SOF6,
                Marker::SOF7,
                Marker::SOF9,
                Marker::SOF10,
                Marker::SOF11,
                Marker::SOF13,
                Marker::SOF14,
                Marker::SOF15,
            ],
            true,
        );
    }

    /**
     * Determines whether the marker begins structural image coding segments before scan data.
     *
     * EXIF 3.0 §4.7.5.2 requires APP11 to be located before DQT/DHT/DRI/SOF marker segments.
     */
    public function isStructuralMarkerBeforeScan(int $marker): bool
    {
        return in_array($marker, [Marker::DQT, Marker::DHT, Marker::DRI], true)
            || $this->isStartOfFrameMarker($marker);
    }

    /**
     * Derives YCbCr subsampling factors from component sampling values.
     *
     * EXIF 3.0 §4.6.5.1.12 (YCbCrSubSampling) defines only [2,1] (YCbCr4:2:2) and
     * [2,2] (YCbCr4:2:0) as legal values. Derived values outside this set are rejected.
     *
     * @param array<int, array{horizontal:int, vertical:int}> $components
     *
     * @return array{0:int,1:int}|null
     */
    private function deriveYCbCrSubSampling(array $components): ?array
    {
        $luma = $components[1] ?? null;
        if ($luma === null) {
            return null;
        }

        $chromas = [];
        foreach ($components as $id => $component) {
            if ($id !== 1) {
                $chromas[] = $component;
            }
        }

        if ($chromas === []) {
            return null;
        }

        $horizontal = $chromas[0]['horizontal'];
        $vertical   = $chromas[0]['vertical'];

        if ($horizontal === 0 || $vertical === 0) {
            return null;
        }

        $count = count($chromas);
        for ($i = 1; $i < $count; ++$i) {
            $component  = $chromas[$i];
            $horizontal = min($horizontal, $component['horizontal']);
            $vertical   = min($vertical, $component['vertical']);
        }

        if ((($luma['horizontal'] % $horizontal) !== 0) || (($luma['vertical'] % $vertical) !== 0)) {
            return null;
        }

        $derivedH = (int) ($luma['horizontal'] / $horizontal);
        $derivedV = (int) ($luma['vertical'] / $vertical);

        // EXIF 3.0 §4.6.5.1.12 lists only [2,1] (4:2:2) and [2,2] (4:2:0)
        // as writer-side requirements.  ITU-T T.81 §B.2.2 allows arbitrary
        // sampling factors including [1,1] (4:4:4 — full chroma resolution).
        // Professional cameras and image editors legitimately produce 4:4:4
        // JPEGs with EXIF metadata.
        $legalValues = [
            [1, 1],
            [2, 1],
            [2, 2],
        ];

        $result = array_any(
            $legalValues,
            fn ($legal): bool => $derivedH === $legal[0] && $derivedV === $legal[1]
        );

        if ($result) {
            return [
                $derivedH,
                $derivedV,
            ];
        }

        return null;
    }
}
