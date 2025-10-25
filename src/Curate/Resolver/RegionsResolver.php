<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Regions;
use MagicSunday\ImageMeta\Value\Regions\Region;
use MagicSunday\ImageMeta\Value\Regions\RegionType;

use function abs;
use function array_shift;
use function array_values;
use function ceil;
use function count;
use function is_array;
use function is_string;
use function log10;
use function max;
use function pow;
use function ksort;
use function trim;

/**
 * Resolves structured region annotations from MWG-RS and Apple FaceInfo metadata.
 */
final readonly class RegionsResolver
{
    use XmpPropertyAccess;

    private const string NS_MWG_REGIONS = 'http://www.metadataworkinggroup.com/schemas/regions/';

    private const string NS_ST_AREA = 'http://ns.adobe.com/xmp/sType/Area#';

    private const string NS_ST_DIMENSIONS = 'http://ns.adobe.com/xmp/sType/Dimensions#';

    private const string NS_APPLE_FACEINFO = 'http://ns.apple.com/faceinfo/1.0/';

    private const float MATCH_THRESHOLD = 0.12;

    /**
     * Builds a regions aggregate from the supplied XMP document.
     */
    public function resolve(?XmpDocument $document): Regions
    {
        if (!$document instanceof XmpDocument) {
            return new Regions([]);
        }

        $dimensions = $this->appliedDimensions($document);
        $mwgRegions = $this->extractMwgRegions($document, $dimensions);
        $appleData  = $this->extractAppleFaceRegions($document, $dimensions, $mwgRegions);
        $supplement = $appleData['supplemental'];
        $mwgRegions = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);
        $appleRegions = $appleData['regions'];

        foreach ($appleRegions as $appleRegion) {
            $matchIndex = $this->findMatchingRegionIndex($mwgRegions, $appleRegion);
            if ($matchIndex !== null) {
                $mwgRegions[$matchIndex] = $this->mergeRegion($mwgRegions[$matchIndex], $appleRegion);
                continue;
            }

            $mwgRegions[] = $appleRegion;
        }

        $mwgRegions = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);

        return new Regions(array_values($mwgRegions));
    }

    /**
     * Extracts MWG-RS region entries.
     *
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return list<Region>
     */
    private function extractMwgRegions(XmpDocument $document, ?array $dimensions): array
    {
        $types        = $this->stringValues($document, self::NS_MWG_REGIONS, 'Type');
        $names        = $this->stringValues($document, self::NS_MWG_REGIONS, 'Name');
        $displayNames = $this->stringValues($document, self::NS_MWG_REGIONS, 'PersonDisplayName');
        $confidences  = $this->floatValues($document, self::NS_MWG_REGIONS, 'Confidence');
        $rotations    = $this->floatValues($document, self::NS_MWG_REGIONS, 'Rotation');
        $centersX     = $this->floatValues($document, self::NS_ST_AREA, 'x');
        $centersY     = $this->floatValues($document, self::NS_ST_AREA, 'y');
        $widths       = $this->floatValues($document, self::NS_ST_AREA, 'w');
        $heights      = $this->floatValues($document, self::NS_ST_AREA, 'h');
        $regionCount  = max(count($centersX), count($centersY), count($widths), count($heights));
        $resolved     = [];

        for ($index = 0; $index < $regionCount; ++$index) {
            $centerX = $centersX[$index] ?? null;
            $centerY = $centersY[$index] ?? null;
            $width   = $widths[$index] ?? null;
            $height  = $heights[$index] ?? null;
            if ($centerX === null) {
                continue;
            }

            if ($centerY === null) {
                continue;
            }

            if ($width === null) {
                continue;
            }

            if ($height === null) {
                continue;
            }

            $normalised = $this->normalisedBox($centerX, $centerY, $width, $height, $dimensions);
            if ($normalised === null) {
                continue;
            }

            $typeLabel = $types[$index] ?? null;
            $type      = $typeLabel !== null ? RegionType::fromLabel($typeLabel) : null;

            $person = $displayNames[$index] ?? $names[$index] ?? null;
            if ($person !== null && $person === '') {
                $person = null;
            }

            $resolved[] = new Region(
                $type,
                $normalised['x'],
                $normalised['y'],
                $normalised['w'],
                $normalised['h'],
                $person,
                $confidences[$index] ?? null,
                $rotations[$index] ?? null,
                null,
            );
        }

        return $resolved;
    }

    /**
     * Extracts Apple FaceInfo face entries along with supplemental metadata.
     *
     * @param array{w: float, h: float}|null $dimensions
     * @param list<Region> $mwgRegions
     *
     * @return array{regions: list<Region>, supplemental: array<int, Region>}
     */
    private function extractAppleFaceRegions(XmpDocument $document, ?array $dimensions, array $mwgRegions): array
    {
        $entries = $this->appleFaceEntries($document, $dimensions);

        return [
            'regions' => $this->regionsFromAppleEntries($entries),
            'supplemental' => $this->supplementalRegionsFromAppleEntries($entries, $mwgRegions),
        ];
    }

    /**
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}>
     */
    private function appleFaceEntries(XmpDocument $document, ?array $dimensions): array
    {
        $centersX         = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'CenterX');
        $centersY         = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'CenterY');
        $widths           = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Width');
        $heights          = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Height');
        $confidenceLevels = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'ConfidenceLevel');
        $confidences      = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Confidence');
        $angleInfoRolls   = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'AngleInfoRoll');
        $rolls            = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Roll');
        $yaws             = $this->floatValues($document, self::NS_APPLE_FACEINFO, 'Yaw');

        $confidenceScale = $this->confidenceScale($confidenceLevels, $confidences);

        $names = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'Name');
        if ($names === []) {
            $names = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FullName');
        }

        $faceIds = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FaceID');
        if ($faceIds === []) {
            $faceIds = $this->stringValues($document, self::NS_APPLE_FACEINFO, 'FaceUUID');
        }

        $count = 0;
        foreach ([$centersX, $centersY, $widths, $heights, $confidenceLevels, $confidences, $angleInfoRolls, $rolls, $yaws, $names, $faceIds] as $values) {
            $valueCount = count($values);
            if ($valueCount > $count) {
                $count = $valueCount;
            }
        }

        if ($count === 0) {
            return [];
        }

        $entries = [];

        for ($index = 0; $index < $count; ++$index) {
            $centerX = $centersX[$index] ?? null;
            $centerY = $centersY[$index] ?? null;
            $width   = $widths[$index] ?? null;
            $height  = $heights[$index] ?? null;

            $geometry = null;
            if ($centerX !== null && $centerY !== null && $width !== null && $height !== null) {
                $geometry = $this->normalisedBox($centerX, $centerY, $width, $height, $dimensions);
            }

            $confidence = $this->normalisedConfidence($confidenceLevels[$index] ?? null, $confidenceScale);
            if ($confidence === null) {
                $confidence = $this->normalisedConfidence($confidences[$index] ?? null, $confidenceScale);
            }

            $rotation = $angleInfoRolls[$index] ?? $rolls[$index] ?? $yaws[$index] ?? null;

            $entries[] = [
                'geometry'   => $geometry,
                'person'     => $this->stringAt($names, $index),
                'confidence' => $confidence,
                'rotation'   => $rotation,
                'faceId'     => $this->stringAt($faceIds, $index),
            ];
        }

        return $entries;
    }

    /**
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries
     *
     * @return list<Region>
     */
    private function regionsFromAppleEntries(array $entries): array
    {
        $regions = [];

        foreach ($entries as $entry) {
            $geometry = $entry['geometry'];
            if ($geometry === null) {
                continue;
            }

            $regions[] = new Region(
                RegionType::FACE,
                $geometry['x'],
                $geometry['y'],
                $geometry['w'],
                $geometry['h'],
                $entry['person'],
                $entry['confidence'],
                $entry['rotation'],
                $entry['faceId'],
            );
        }

        return $regions;
    }

    /**
     * @param list<Region> $regions
     * @param array<int, Region> $supplemental
     *
     * @return list<Region>
     */
    private function applyAppleSupplementalMetadata(array $regions, array $supplemental): array
    {
        if ($supplemental === []) {
            return $regions;
        }

        foreach ($supplemental as $index => $supplement) {
            $baseRegion = $regions[$index] ?? null;
            if (!$baseRegion instanceof Region) {
                continue;
            }

            $regions[$index] = $this->mergeRegion($baseRegion, $supplement);
        }

        return $regions;
    }

    /**
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries
     * @param list<Region> $mwgRegions
     *
     * @return array<int, Region>
     */
    private function supplementalRegionsFromAppleEntries(array $entries, array $mwgRegions): array
    {
        if ($entries === [] || $mwgRegions === []) {
            return [];
        }

        $faceIndices = [];
        foreach ($mwgRegions as $index => $region) {
            if ($region->type === RegionType::FACE) {
                $faceIndices[] = $index;
            }
        }

        if ($faceIndices === []) {
            return [];
        }

        $unmatchedIndices = $faceIndices;
        $supplemental     = [];

        foreach ($entries as $entry) {
            $matchIndex = $this->matchAppleEntryToMwgRegion($mwgRegions, $entry);
            if ($matchIndex === null) {
                continue;
            }

            $unmatchedIndices = $this->removeMatchedIndex($unmatchedIndices, $matchIndex);

            if (!$this->hasSupplementalMetadata($entry)) {
                continue;
            }

            $baseRegion            = $mwgRegions[$matchIndex];
            $supplemental[$matchIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        foreach ($entries as $entry) {
            if ($entry['geometry'] !== null) {
                continue;
            }

            if (!$this->hasSupplementalMetadata($entry)) {
                continue;
            }

            $nextIndex = array_shift($unmatchedIndices);
            if ($nextIndex === null) {
                break;
            }

            $baseRegion             = $mwgRegions[$nextIndex];
            $supplemental[$nextIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        if ($supplemental === []) {
            return [];
        }

        ksort($supplemental);

        return $supplemental;
    }

    /**
     * @param list<Region> $mwgRegions
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
     */
    private function matchAppleEntryToMwgRegion(array $mwgRegions, array $entry): ?int
    {
        $geometry = $entry['geometry'];
        if ($geometry === null) {
            return null;
        }

        $candidate = new Region(
            RegionType::FACE,
            $geometry['x'],
            $geometry['y'],
            $geometry['w'],
            $geometry['h'],
            $entry['person'],
            $entry['confidence'],
            $entry['rotation'],
            $entry['faceId'],
        );

        return $this->findMatchingRegionIndex($mwgRegions, $candidate);
    }

    /**
     * @param list<int> $indices
     *
     * @return list<int>
     */
    private function removeMatchedIndex(array $indices, int $match): array
    {
        foreach ($indices as $position => $index) {
            if ($index === $match) {
                unset($indices[$position]);
                break;
            }
        }

        return array_values($indices);
    }

    /**
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
     */
    private function createSupplementalRegion(Region $baseRegion, array $entry): Region
    {
        return new Region(
            $baseRegion->type ?? RegionType::FACE,
            $baseRegion->x,
            $baseRegion->y,
            $baseRegion->w,
            $baseRegion->h,
            $entry['person'],
            $entry['confidence'],
            $entry['rotation'],
            $entry['faceId'],
        );
    }

    /**
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry
     */
    private function hasSupplementalMetadata(array $entry): bool
    {
        return $entry['person'] !== null
            || $entry['confidence'] !== null
            || $entry['rotation'] !== null
            || $entry['faceId'] !== null;
    }

    /**
     * Attempts to match an Apple face region with an MWG region by spatial overlap.
     *
     * @param array<int, Region> $regions
     */
    private function findMatchingRegionIndex(array $regions, Region $candidate): ?int
    {
        if ($candidate->type !== RegionType::FACE) {
            return null;
        }

        $bestIndex             = null;
        $bestScore             = null;
        [$targetCx, $targetCy] = $this->regionCenter($candidate);

        foreach ($regions as $index => $region) {
            if ($region->type !== RegionType::FACE) {
                continue;
            }

            [$cx, $cy] = $this->regionCenter($region);
            $distance  = abs($cx - $targetCx) + abs($cy - $targetCy);
            if ($distance > self::MATCH_THRESHOLD) {
                continue;
            }

            $sizeDiff = abs($region->w - $candidate->w) + abs($region->h - $candidate->h);
            $score    = $distance + $sizeDiff;
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Merges overlapping region metadata, preferring existing geometry while enriching attributes.
     *
     * @param Region $base       Primary region resolved from MWG metadata.
     * @param Region $supplement Supplementary region derived from Apple metadata.
     *
     * @return Region Combined region carrying the most complete metadata set.
     */
    private function mergeRegion(Region $base, Region $supplement): Region
    {
        $person     = $base->personName ?? $supplement->personName;
        $confidence = $base->confidence;
        if ($confidence === null) {
            $confidence = $supplement->confidence;
        } elseif ($supplement->confidence !== null) {
            $confidence = max($confidence, $supplement->confidence);
        }

        $rotation = $base->rotationDeg ?? $supplement->rotationDeg;
        $faceId   = $base->faceId ?? $supplement->faceId;
        $type     = $base->type ?? $supplement->type;

        return new Region(
            $type,
            $base->x,
            $base->y,
            $base->w,
            $base->h,
            $person,
            $confidence,
            $rotation,
            $faceId,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function regionCenter(Region $region): array
    {
        return [
            $region->x + ($region->w / 2.0),
            $region->y + ($region->h / 2.0),
        ];
    }

    /**
     * @param list<string> $values
     */
    private function stringAt(array $values, int $index): ?string
    {
        $value = $values[$index] ?? null;
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalises Apple-specific confidence values to the unit interval.
     */
    private function normalisedConfidence(?float $confidence, float $scale): ?float
    {
        if ($confidence === null) {
            return null;
        }

        if ($scale <= 1.0 || abs($confidence) <= 1.0) {
            return $confidence;
        }

        $normalised = $confidence / $scale;

        if ($normalised > 1.0) {
            return 1.0;
        }

        if ($normalised < -1.0) {
            return -1.0;
        }

        return $normalised;
    }

    /**
     * @param list<float|null> $confidenceLevels
     * @param list<float|null> $confidences
     */
    private function confidenceScale(array $confidenceLevels, array $confidences): float
    {
        $maxConfidence = 0.0;

        foreach ([$confidenceLevels, $confidences] as $values) {
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }

                $absolute = abs($value);
                if ($absolute > $maxConfidence) {
                    $maxConfidence = $absolute;
                }
            }
        }

        if ($maxConfidence <= 1.0) {
            return 1.0;
        }

        $scale = pow(10.0, ceil(log10($maxConfidence)));

        if ($scale <= 0.0) {
            return 1.0;
        }

        return $scale;
    }

    /**
     * @param array{w: float, h: float}|null $dimensions
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function normalisedBox(float $centerX, float $centerY, float $width, float $height, ?array $dimensions): ?array
    {
        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $scaledCenterX = $centerX;
        $scaledCenterY = $centerY;
        $scaledWidth   = $width;
        $scaledHeight  = $height;

        if ($dimensions !== null) {
            if ($scaledCenterX > 1.0 || $scaledWidth > 1.0) {
                $scaledCenterX /= $dimensions['w'];
                $scaledWidth /= $dimensions['w'];
            }

            if ($scaledCenterY > 1.0 || $scaledHeight > 1.0) {
                $scaledCenterY /= $dimensions['h'];
                $scaledHeight /= $dimensions['h'];
            }
        }

        if (($scaledCenterX > 1.0 || $scaledCenterY > 1.0 || $scaledWidth > 1.0 || $scaledHeight > 1.0) && ($scaledCenterX <= 100.0 && $scaledCenterY <= 100.0 && $scaledWidth <= 100.0 && $scaledHeight <= 100.0)) {
            $scaledCenterX /= 100.0;
            $scaledCenterY /= 100.0;
            $scaledWidth /= 100.0;
            $scaledHeight /= 100.0;
        }

        $halfWidth  = $scaledWidth / 2.0;
        $halfHeight = $scaledHeight / 2.0;

        return [
            'x' => $this->clamp($scaledCenterX - $halfWidth),
            'y' => $this->clamp($scaledCenterY - $halfHeight),
            'w' => $this->clamp($scaledWidth),
            'h' => $this->clamp($scaledHeight),
        ];
    }

    /**
     * Constrains a normalised coordinate to the unit interval.
     *
     * @param float $value Coordinate or dimension value to clamp.
     *
     * @return float Value restricted to the range [0.0, 1.0].
     */
    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $this->xmpValue($document, $namespace, $localName);

        if (is_string($raw)) {
            $trimmed = trim($raw);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if (!is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $values[] = trim($value);
        }

        return $values;
    }

    /**
     * @return list<float|null>
     */
    private function floatValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $this->xmpValue($document, $namespace, $localName);

        if (is_string($raw)) {
            $raw = [$raw];
        } elseif (!is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                $values[] = null;
                continue;
            }

            $numeric  = $this->parseNumericString($value);
            $values[] = $numeric;
        }

        return $values;
    }

    /**
     * @return array{w: float, h: float}|null
     */
    private function appliedDimensions(XmpDocument $document): ?array
    {
        $widths  = $this->floatValues($document, self::NS_ST_DIMENSIONS, 'w');
        $heights = $this->floatValues($document, self::NS_ST_DIMENSIONS, 'h');

        $width  = $widths[0] ?? null;
        $height = $heights[0] ?? null;

        if ($width === null || $width <= 0.0 || $height === null || $height <= 0.0) {
            return null;
        }

        return ['w' => $width, 'h' => $height];
    }
}
