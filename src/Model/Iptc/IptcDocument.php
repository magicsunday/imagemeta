<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Iptc;

use function array_key_exists;

/**
 * Immutable representation of IPTC IIM datasets extracted from APP13 payloads.
 */
final readonly class IptcDocument
{
    /**
     * @param array<string, list<string>> $datasets Map of "record:dataset" => list of values.
     */
    public function __construct(public array $datasets)
    {
    }

    /**
     * Merges multiple IPTC documents into a single aggregate.
     */
    public static function merge(self ...$documents): self
    {
        if ($documents === []) {
            return new self([]);
        }

        /** @var array<string, list<string>> $datasets */
        $datasets = [];

        foreach ($documents as $document) {
            foreach ($document->datasets as $key => $values) {
                if (!array_key_exists($key, $datasets)) {
                    $datasets[$key] = [];
                }

                foreach ($values as $value) {
                    $datasets[$key][] = $value;
                }
            }
        }

        return new self($datasets);
    }

    /**
     * Returns all values for the requested record/dataset combination.
     *
     * @return list<string>
     */
    public function values(int $record, int $dataset): array
    {
        $key = $this->key($record, $dataset);

        return $this->datasets[$key] ?? [];
    }

    /**
     * Returns the first value for the requested record/dataset combination.
     */
    public function first(int $record, int $dataset): ?string
    {
        return $this->values($record, $dataset)[0] ?? null;
    }

    /**
     * Indicates whether the document contains a dataset for the requested key.
     */
    public function has(int $record, int $dataset): bool
    {
        return array_key_exists($this->key($record, $dataset), $this->datasets);
    }

    /**
     * Builds the composite array key for a record/dataset pair.
     *
     * @param int $record  IPTC record number.
     * @param int $dataset IPTC dataset number.
     *
     * @return string Composite key.
     */
    private function key(int $record, int $dataset): string
    {
        return $record . ':' . $dataset;
    }
}
