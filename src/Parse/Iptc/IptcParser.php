<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Iptc;

use MagicSunday\ImageMeta\Contract\IptcParserInterface;
use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;

use function array_key_exists;
use function ord;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Parses IPTC IIM datasets embedded in Photoshop APP13 resource blocks.
 *
 * @phpstan-type IptcDatasetMap = array<string, list<string>>
 */
final class IptcParser implements IptcParserInterface
{
    private const string PHOTOSHOP_SIGNATURE              = "Photoshop 3.0\0";

    private const string RESOURCE_SIGNATURE               = '8BIM';

    private const int IPTC_RESOURCE_ID                    = 0x0404;

    /**
     * IIM extended-length indicator bit (ISO 7160).
     * When set in the length field, the remaining bits encode the byte-count of the actual length.
     */
    private const int IIM_EXTENDED_LENGTH_FLAG            = 0x8000;

    /**
     * Mask to extract the extended-length byte-count from the length field (ISO 7160).
     */
    private const int IIM_EXTENDED_LENGTH_BYTE_COUNT_MASK = 0x7FFF;

    private const int IIM_EXTENDED_LENGTH_MAX_BYTES       = 4;

    /**
     * Parses the supplied APP13 payload and returns the decoded IPTC datasets.
     *
     * @param string $payload Raw APP13 payload including the Photoshop signature.
     *
     * @throws ParseError
     * @throws BoundsError
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
     * @return IptcDatasetMap
     */
    private function parseResourceBlocks(string $payload, int $offset): array
    {
        $length   = strlen($payload);
        /** @var IptcDatasetMap $datasets */
        $datasets = [];

        while ($offset < $length) {
            $remaining       = $length - $offset;

            if ($remaining < 4) {
                throw new BoundsError('APP13 resource block header exceeds payload length.', 1126);
            }

            $signature       = substr($payload, $offset, 4);
            $offset += 4;

            if ($signature !== self::RESOURCE_SIGNATURE) {
                break;
            }

            if (($length - $offset) < 2) {
                throw new BoundsError('APP13 resource ID exceeds payload length.', 1128);
            }

            $resourceId      = Unpack::int('n', substr($payload, $offset, 2), 'IPTC resource ID');
            $offset += 2;

            if (($length - $offset) < 1) {
                throw new BoundsError('APP13 resource name exceeds payload length.', 1129);
            }

            $nameLength      = ord($payload[$offset]);
            ++$offset;

            if (($length - $offset) < $nameLength) {
                throw new BoundsError('APP13 resource name exceeds payload length.', 1130);
            }

            $offset += $nameLength;

            $nameFieldLength = 1 + $nameLength;

            if (($nameFieldLength % 2) !== 0) {
                if (($length - $offset) < 1) {
                    throw new BoundsError('APP13 resource name padding exceeds payload length.', 1131);
                }

                // Tolerate non-zero name padding bytes.
                ++$offset;
            }

            if (($length - $offset) < 4) {
                throw new BoundsError('APP13 resource size exceeds payload length.', 1132);
            }

            $resourceSize    = Unpack::int('N', substr($payload, $offset, 4), 'IPTC resource size');
            $offset += 4;

            if (($length - $offset) < $resourceSize) {
                throw new BoundsError('APP13 resource data exceeds payload length.', 1133);
            }

            $data            = substr($payload, $offset, $resourceSize);
            $offset += $resourceSize;

            if (($resourceSize % 2) !== 0) {
                if (($length - $offset) < 1) {
                    throw new BoundsError('APP13 resource data padding exceeds payload length.', 1142);
                }

                // Tolerate non-zero data padding bytes.
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
     * By-ref accumulator: the method appends discovered datasets in a loop; returning the
     * array on each iteration would degrade readability without functional benefit.
     *
     * @param string         $data     Raw IPTC IIM data.
     * @param IptcDatasetMap $datasets Map to accumulate into.
     */
    private function parseIimData(string $data, array &$datasets): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            if (($length - $offset) < 5) {
                break;
            }

            if (ord($data[$offset]) !== 0x1C) {
                break;
            }

            $recordNumber     = ord($data[$offset + 1]);
            $datasetNumber    = ord($data[$offset + 2]);
            $lengthField      = Unpack::int('n', substr($data, $offset + 3, 2), 'IPTC record size');
            $offset += 5;

            $valueLength      = $lengthField;

            if (($lengthField & self::IIM_EXTENDED_LENGTH_FLAG) !== 0) {
                $lengthBytes = $lengthField & self::IIM_EXTENDED_LENGTH_BYTE_COUNT_MASK;

                if ($lengthBytes === 0) {
                    throw new ParseError('IPTC IIM extended length-byte-count must be greater than zero.', 1869);
                }

                if ($lengthBytes > self::IIM_EXTENDED_LENGTH_MAX_BYTES) {
                    throw new ParseError(
                        sprintf(
                            'IPTC IIM extended length-byte-count %d exceeds maximum supported value %d.',
                            $lengthBytes,
                            self::IIM_EXTENDED_LENGTH_MAX_BYTES,
                        ),
                        1969,
                    );
                }

                if (($length - $offset) < $lengthBytes) {
                    throw new BoundsError('IPTC IIM extended length exceeds payload length.', 1136);
                }

                $valueLength = 0;

                for ($index = 0; $index < $lengthBytes; ++$index) {
                    $valueLength = ($valueLength << 8) | ord($data[$offset + $index]);
                }

                $offset += $lengthBytes;
            }

            if (($length - $offset) < $valueLength) {
                throw new BoundsError('IPTC IIM dataset value exceeds payload length.', 1137);
            }

            $value            = substr($data, $offset, $valueLength);
            $offset += $valueLength;

            $key              = $recordNumber . ':' . $datasetNumber;

            if (!array_key_exists($key, $datasets)) {
                $datasets[$key] = [];
            }

            $datasets[$key][] = $value;
        }
    }
}
