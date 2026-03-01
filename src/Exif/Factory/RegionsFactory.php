<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Exif\Factory;

use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpNamespace;
use MagicSunday\ImageMeta\Value\Enum\RegionType;
use MagicSunday\ImageMeta\Value\Region;
use MagicSunday\ImageMeta\Value\RegionCollection;

use function abs;
use function array_map;
use function array_shift;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function ksort;
use function max;
use function trim;

/**
 * Factory for creating RegionCollection value objects from XMP metadata.
 */
final readonly class RegionsFactory
{
    private const float MATCH_THRESHOLD = 0.12;

    private RegionCoordinateNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new RegionCoordinateNormalizer();
    }

    /**
     * Creates a RegionCollection value object from XMP metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return RegionCollection Regions metadata aggregate with face and region detection data.
     */
    public function create(Metadata $metadata): RegionCollection
    {
        $xmpDocument = $metadata->xmpDoc ?? $metadata->selectiveXmpDocument();

        return $this->resolveRegions($xmpDocument);
    }

    /**
     * Builds region metadata from an XMP document when present.
     *
     * @param XmpDocument|null $document XMP document to inspect.
     *
     * @return RegionCollection Resolved regions collection.
     */
    private function resolveRegions(?XmpDocument $document): RegionCollection
    {
        if (!$document instanceof XmpDocument) {
            return new RegionCollection([]);
        }

        $dimensions   = $this->appliedDimensions($document);
        $mwgRegions   = $this->extractMwgRegions($document, $dimensions);
        $appleData    = $this->extractAppleFaceRegions($document, $dimensions, $mwgRegions);
        $supplement   = $appleData['supplemental'];
        $mwgRegions   = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);
        $appleRegions = $appleData['regions'];

        foreach ($appleRegions as $appleRegion) {
            $matchIndex = $this->findMatchingRegionIndex($mwgRegions, $appleRegion);
            if ($matchIndex !== null) {
                $mwgRegions[$matchIndex] = $this->mergeRegion($mwgRegions[$matchIndex], $appleRegion);
            } else {
                $mwgRegions[] = $appleRegion;
            }
        }

        $mwgRegions = $this->applyAppleSupplementalMetadata($mwgRegions, $supplement);

        /** @var list<Region> $normalizedRegions */
        $normalizedRegions = array_values($mwgRegions);

        return new RegionCollection($normalizedRegions);
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
        $types        = $this->stringValues($document, XmpNamespace::MWG_REGIONS->value, 'Type');
        $names        = $this->stringValues($document, XmpNamespace::MWG_REGIONS->value, 'Name');
        $displayNames = $this->stringValues($document, XmpNamespace::MWG_REGIONS->value, 'PersonDisplayName');
        $confidences  = $this->floatValues($document, XmpNamespace::MWG_REGIONS->value, 'Confidence');
        $rotations    = $this->floatValues($document, XmpNamespace::MWG_REGIONS->value, 'Rotation');

        $geometry    = $this->extractGeometryArrays($document, XmpNamespace::ST_AREA->value, 'x', 'y', 'w', 'h');
        $centersX    = $geometry['centersX'];
        $centersY    = $geometry['centersY'];
        $widths      = $geometry['widths'];
        $heights     = $geometry['heights'];
        $regionCount = $geometry['count'];
        $resolved    = [];

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

            $normalized = $this->normalizer->normalizedBox($centerX, $centerY, $width, $height, $dimensions);
            if ($normalized === null) {
                continue;
            }

            $typeLabel = $types[$index] ?? null;
            $type      = $typeLabel !== null ? RegionType::fromLabel($typeLabel) : null;
            $person    = $displayNames[$index] ?? $names[$index] ?? null;

            if ($person === '') {
                $person = null;
            }

            $resolved[] = new Region(
                $type,
                $normalized['x'],
                $normalized['y'],
                $normalized['w'],
                $normalized['h'],
                $person,
                $confidences[$index] ?? null,
                $rotations[$index] ?? null,
            );
        }

        return $resolved;
    }

    /**
     * Extracts Apple FaceInfo face entries along with supplemental metadata.
     *
     * @param array{w: float, h: float}|null $dimensions
     * @param list<Region>                   $mwgRegions
     *
     * @return array{regions: list<Region>, supplemental: array<int, Region>}
     */
    private function extractAppleFaceRegions(XmpDocument $document, ?array $dimensions, array $mwgRegions): array
    {
        $entries = $this->appleFaceEntries($document, $dimensions);

        return [
            'regions'      => $this->regionsFromAppleEntries($entries),
            'supplemental' => $this->supplementalRegionsFromAppleEntries($entries, $mwgRegions),
        ];
    }

    /**
     * Extracts Apple face region entries from XMP document.
     *
     * @param XmpDocument                    $document   XMP document to extract from.
     * @param array{w: float, h: float}|null $dimensions Image dimensions for normalization.
     *
     * @return list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> List of Apple face entries.
     */
    private function appleFaceEntries(XmpDocument $document, ?array $dimensions): array
    {
        $geometry         = $this->extractGeometryArrays($document, XmpNamespace::APPLE_FACEINFO->value, 'CenterX', 'CenterY', 'Width', 'Height');
        $centersX         = $geometry['centersX'];
        $centersY         = $geometry['centersY'];
        $widths           = $geometry['widths'];
        $heights          = $geometry['heights'];
        $confidenceLevels = $this->floatValues($document, XmpNamespace::APPLE_FACEINFO->value, 'ConfidenceLevel');
        $confidences      = $this->floatValues($document, XmpNamespace::APPLE_FACEINFO->value, 'Confidence');
        $angleInfoRolls   = $this->floatValues($document, XmpNamespace::APPLE_FACEINFO->value, 'AngleInfoRoll');
        $rolls            = $this->floatValues($document, XmpNamespace::APPLE_FACEINFO->value, 'Roll');
        $yaws             = $this->floatValues($document, XmpNamespace::APPLE_FACEINFO->value, 'Yaw');

        $confidenceScale = $this->normalizer->confidenceScale($confidenceLevels, $confidences);

        $names = $this->stringValues($document, XmpNamespace::APPLE_FACEINFO->value, 'Name');
        if ($names === []) {
            $names = $this->stringValues($document, XmpNamespace::APPLE_FACEINFO->value, 'FullName');
        }

        $faceIds = $this->stringValues($document, XmpNamespace::APPLE_FACEINFO->value, 'FaceID');
        if ($faceIds === []) {
            $faceIds = $this->stringValues($document, XmpNamespace::APPLE_FACEINFO->value, 'FaceUUID');
        }

        $count = $geometry['count'];
        foreach ([$confidenceLevels, $confidences, $angleInfoRolls, $rolls, $yaws, $names, $faceIds] as $values) {
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
            if (($centerX !== null) && ($centerY !== null) && ($width !== null) && ($height !== null)) {
                $geometry = $this->normalizer->normalizedBox($centerX, $centerY, $width, $height, $dimensions);
            }

            $confidence = $this->normalizer->normalizedConfidence($confidenceLevels[$index] ?? null, $confidenceScale);
            if ($confidence === null) {
                $confidence = $this->normalizer->normalizedConfidence($confidences[$index] ?? null, $confidenceScale);
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
     * Converts Apple face entries to Region value objects.
     *
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries Apple face entries.
     *
     * @return list<Region> List of Region value objects.
     */
    private function regionsFromAppleEntries(array $entries): array
    {
        $regions = [];

        foreach ($entries as $entry) {
            $geometry = $entry['geometry'];
            if ($geometry !== null) {
                $regions[] = new Region(
                    RegionType::Face,
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
        }

        return $regions;
    }

    /**
     * Applies Apple supplemental metadata to existing regions.
     *
     * @param array<int, Region> $regions      Existing regions.
     * @param array<int, Region> $supplemental Supplemental regions to merge.
     *
     * @return array<int, Region> Updated regions with supplemental data applied.
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
     * Creates supplemental regions from Apple entries matched to MWG regions.
     *
     * @param list<array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null}> $entries    Apple face entries.
     * @param list<Region>                                                                                                                                                      $mwgRegions MWG region list for matching.
     *
     * @return array<int, Region> Supplemental regions indexed by MWG region position.
     */
    private function supplementalRegionsFromAppleEntries(array $entries, array $mwgRegions): array
    {
        if ($entries === [] || $mwgRegions === []) {
            return [];
        }

        // Collect indices of MWG face regions eligible for supplemental Apple metadata.
        $faceIndices = [];
        foreach ($mwgRegions as $index => $region) {
            if ($region->type === RegionType::Face) {
                $faceIndices[] = $index;
            }
        }

        if ($faceIndices === []) {
            return [];
        }

        // Track face indices still lacking a matched Apple entry.
        $unmatchedIndices = $faceIndices;
        $supplemental     = [];

        foreach ($entries as $entry) {
            // Align geometry-bearing Apple entries with MWG faces based on their shared shape.
            $matchIndex = $this->matchAppleEntryToMwgRegion($mwgRegions, $entry);
            if ($matchIndex === null) {
                continue;
            }

            $unmatchedIndices = $this->removeMatchedIndex($unmatchedIndices, $matchIndex);

            if (!$this->hasSupplementalMetadata($entry)) {
                continue;
            }

            $baseRegion                = $mwgRegions[$matchIndex];
            $supplemental[$matchIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        // Assign remaining supplemental details to faces even when Apple omitted geometry.
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

            $baseRegion               = $mwgRegions[$nextIndex];
            $supplemental[$nextIndex] = $this->createSupplementalRegion($baseRegion, $entry);
        }

        if ($supplemental === []) {
            return [];
        }

        ksort($supplemental);

        return $supplemental;
    }

    /**
     * Matches an Apple face entry to an MWG region by geometry.
     *
     * @param list<Region>                                                                                                                                                $mwgRegions MWG region list.
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry      Apple face entry to match.
     *
     * @return int|null Index of matching MWG region or null if no match.
     */
    private function matchAppleEntryToMwgRegion(array $mwgRegions, array $entry): ?int
    {
        $geometry = $entry['geometry'];
        if ($geometry === null) {
            return null;
        }

        $candidate = new Region(
            RegionType::Face,
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
     * Removes a matched index from a list of indices.
     *
     * @param list<int> $indices List of indices.
     * @param int       $match   Index to remove.
     *
     * @return list<int> Updated list without the matched index.
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
     * Creates a supplemental region with Apple-specific metadata.
     *
     * @param Region                                                                                                                                                      $baseRegion Base region to supplement.
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry      Apple face entry data.
     *
     * @return Region Enhanced region with supplemental metadata.
     */
    private function createSupplementalRegion(Region $baseRegion, array $entry): Region
    {
        return new Region(
            $baseRegion->type ?? RegionType::Face,
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
     * Checks if an Apple face entry contains supplemental metadata.
     *
     * @param array{geometry: array{x: float, y: float, w: float, h: float}|null, person: string|null, confidence: float|null, rotation: float|null, faceId: string|null} $entry Apple face entry.
     *
     * @return bool True if entry has supplemental metadata.
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
        if ($candidate->type !== RegionType::Face) {
            return null;
        }

        $bestIndex             = null;
        $bestScore             = null;
        [$targetCx, $targetCy] = $this->normalizer->regionCenter($candidate);

        foreach ($regions as $index => $region) {
            if ($region->type !== RegionType::Face) {
                continue;
            }

            [$cx, $cy] = $this->normalizer->regionCenter($region);
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
     * Retrieves a string value at a specific index from a list.
     *
     * @param list<string> $values List of string values.
     * @param int          $index  Index to retrieve.
     *
     * @return string|null String value at index or null if not found.
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
     * Extracts a list of string values from XMP document.
     *
     * @param XmpDocument $document  XMP document to extract from.
     * @param string      $namespace XML namespace URI.
     * @param string      $localName Local element name.
     *
     * @return list<string> List of string values.
     */
    private function stringValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $document->get($namespace, $localName);

        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }

            return array_values(array_map(trim(...), $raw));
        }

        if (!is_string($raw)) {
            return [];
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? [] : [$trimmed];
    }

    /**
     * Extracts a list of float values from XMP document.
     *
     * @param XmpDocument $document  XMP document to extract from.
     * @param string      $namespace XML namespace URI.
     * @param string      $localName Local element name.
     *
     * @return list<float|null> List of float values with nulls for invalid entries.
     */
    private function floatValues(XmpDocument $document, string $namespace, string $localName): array
    {
        $raw = $document->get($namespace, $localName);

        if (is_array($raw)) {
            if ($raw === []) {
                return [];
            }
        } elseif (is_string($raw)) {
            $raw = [$raw];
        } else {
            return [];
        }

        return array_values(array_map(XmpDocument::parseNumericValue(...), $raw));
    }

    /**
     * Extracts geometry arrays (centerX, centerY, width, height) and their count from XMP.
     *
     * @return array{centersX: list<float|null>, centersY: list<float|null>, widths: list<float|null>, heights: list<float|null>, count: int}
     */
    private function extractGeometryArrays(
        XmpDocument $document,
        string $namespace,
        string $xKey,
        string $yKey,
        string $wKey,
        string $hKey,
    ): array {
        $centersX = $this->floatValues($document, $namespace, $xKey);
        $centersY = $this->floatValues($document, $namespace, $yKey);
        $widths   = $this->floatValues($document, $namespace, $wKey);
        $heights  = $this->floatValues($document, $namespace, $hKey);
        $count    = max(count($centersX), count($centersY), count($widths), count($heights));

        return [
            'centersX' => $centersX,
            'centersY' => $centersY,
            'widths'   => $widths,
            'heights'  => $heights,
            'count'    => $count,
        ];
    }

    /**
     * Extracts applied image dimensions from XMP document.
     *
     * @param XmpDocument $document XMP document to extract from.
     *
     * @return array{w: float, h: float}|null Image dimensions or null if not found.
     */
    private function appliedDimensions(XmpDocument $document): ?array
    {
        $widths  = $this->floatValues($document, XmpNamespace::ST_DIMENSIONS->value, 'w');
        $heights = $this->floatValues($document, XmpNamespace::ST_DIMENSIONS->value, 'h');

        $width  = $widths[0] ?? null;
        $height = $heights[0] ?? null;

        if ($width === null || $width <= 0.0 || $height === null || $height <= 0.0) {
            return null;
        }

        return ['w' => $width, 'h' => $height];
    }
}
