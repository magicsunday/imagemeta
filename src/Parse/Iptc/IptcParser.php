<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Iptc;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;

use function array_key_exists;
use function is_array;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function unpack;

/**
 * Parses IPTC IIM datasets embedded in Photoshop APP13 resource blocks.
 */
final class IptcParser
{
    private const string PHOTOSHOP_SIGNATURE = "Photoshop 3.0\0";

    private const string RESOURCE_SIGNATURE = '8BIM';

    private const int IPTC_RESOURCE_ID = 0x0404;

    /**
     * Parses the supplied APP13 payload and returns the decoded IPTC datasets.
     *
     * @param string $payload Raw APP13 payload including the Photoshop signature.
     */
    public function parse(string $payload): IptcDocument
    {
        if (!str_starts_with($payload, self::PHOTOSHOP_SIGNATURE)) {
            return new IptcDocument([]);
        }

        $datasets = $this->parseResourceBlocks($payload, strlen(self::PHOTOSHOP_SIGNATURE));

        return new IptcDocument($datasets);
    }

    /**
     * Iterates through Photoshop resource blocks and extracts IPTC IIM data sets.
     *
     * @param string $payload Raw APP13 payload including the Photoshop signature.
     * @param int    $offset  Start offset for resource block parsing.
     *
     * @return array<string, list<string>>
     */
    private function parseResourceBlocks(string $payload, int $offset): array
    {
        $length = strlen($payload);
        /** @var array<string, list<string>> $datasets */
        $datasets = [];

        while ($offset < $length) {
            $remaining = $length - $offset;
            if ($remaining < 4) {
                throw new BoundsError('APP13 resource block header exceeds payload length.');
            }

            $signature = substr($payload, $offset, 4);
            $offset += 4;

            if ($signature !== self::RESOURCE_SIGNATURE) {
                throw new ParseError(sprintf('Unexpected resource signature "%s" in APP13 payload.', $signature));
            }

            if (($length - $offset) < 2) {
                throw new BoundsError('APP13 resource ID exceeds payload length.');
            }

            $resourceId = $this->readUnsignedShort($payload, $offset);
            $offset += 2;

            if (($length - $offset) < 1) {
                throw new BoundsError('APP13 resource name exceeds payload length.');
            }

            $nameLength = ord($payload[$offset]);
            ++$offset;

            if (($length - $offset) < $nameLength) {
                throw new BoundsError('APP13 resource name exceeds payload length.');
            }

            $offset += $nameLength;

            $nameFieldLength = 1 + $nameLength;
            if (($nameFieldLength % 2) !== 0) {
                if (($length - $offset) < 1) {
                    throw new BoundsError('APP13 resource name padding exceeds payload length.');
                }

                ++$offset;
            }

            if (($length - $offset) < 4) {
                throw new BoundsError('APP13 resource size exceeds payload length.');
            }

            $resourceSize = $this->readUnsignedLong($payload, $offset);
            $offset += 4;

            if (($length - $offset) < $resourceSize) {
                throw new BoundsError('APP13 resource data exceeds payload length.');
            }

            $data = substr($payload, $offset, $resourceSize);
            $offset += $resourceSize;

            if ((($resourceSize % 2) !== 0) && (($length - $offset) >= 1)) {
                ++$offset;
            }

            if ($resourceId === self::IPTC_RESOURCE_ID) {
                $this->parseIimData($data, $datasets);
            }
        }

        return $datasets;
    }

    /**
     * Parses IPTC IIM data sets into the dataset map.
     *
     * @param string                      $data     Raw IPTC IIM data.
     * @param array<string, list<string>> $datasets Map to accumulate into.
     */
    private function parseIimData(string $data, array &$datasets): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            if (($length - $offset) < 5) {
                throw new BoundsError('IPTC IIM dataset header exceeds payload length.');
            }

            if (ord($data[$offset]) !== 0x1C) {
                throw new ParseError('IPTC IIM dataset marker is missing.');
            }

            $recordNumber  = ord($data[$offset + 1]);
            $datasetNumber = ord($data[$offset + 2]);
            $lengthField   = $this->readUnsignedShort($data, $offset + 3);
            $offset += 5;

            $valueLength = $lengthField;
            if (($lengthField & 0x8000) !== 0) {
                $lengthBytes = $lengthField & 0x7FFF;
                if (($length - $offset) < $lengthBytes) {
                    throw new BoundsError('IPTC IIM extended length exceeds payload length.');
                }

                $valueLength = 0;
                for ($index = 0; $index < $lengthBytes; ++$index) {
                    $valueLength = ($valueLength << 8) | ord($data[$offset + $index]);
                }

                $offset += $lengthBytes;
            }

            if (($length - $offset) < $valueLength) {
                throw new BoundsError('IPTC IIM dataset value exceeds payload length.');
            }

            $value = substr($data, $offset, $valueLength);
            $offset += $valueLength;

            $key = $recordNumber . ':' . $datasetNumber;
            if (!array_key_exists($key, $datasets)) {
                $datasets[$key] = [];
            }

            $datasets[$key][] = $value;
        }
    }

    private function readUnsignedShort(string $data, int $offset): int
    {
        $unpacked = @unpack('nvalue', substr($data, $offset, 2));

        if (!is_array($unpacked) || !isset($unpacked['value']) || !is_int($unpacked['value'])) {
            throw new ParseError('Unable to read IPTC short value.');
        }

        return $unpacked['value'];
    }

    private function readUnsignedLong(string $data, int $offset): int
    {
        $unpacked = @unpack('Nvalue', substr($data, $offset, 4));

        if (!is_array($unpacked) || !isset($unpacked['value']) || !is_int($unpacked['value'])) {
            throw new ParseError('Unable to read IPTC long value.');
        }

        return $unpacked['value'];
    }
}
