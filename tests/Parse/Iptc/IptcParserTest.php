<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Iptc;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Parse\Iptc\IptcParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Exercises IPTC dataset parsing from Photoshop APP13 resource blocks.
 * It verifies repeated datasets are preserved and extended-length values decode correctly.
 * The tests include truncated blocks to assert BoundsError/ParseError behavior.
 * This ensures IPTC extraction remains robust against malformed APP13 payloads.
 *
 * @internal
 */
#[CoversClass(IptcParser::class)]
#[UsesClass(IptcDocument::class)]
final class IptcParserTest extends TestCase
{
    private const string PHOTOSHOP_SIGNATURE = "Photoshop 3.0\0";

    /**
     * Parses IPTC datasets embedded in a Photoshop APP13 resource block.
     * This confirms multiple values for the same dataset are preserved in order.
     */
    #[Test]
    public function parsesIimDatasetsFromApp13Payload(): void
    {
        $iimData = $this->iimDataset(2, 5, 'Object Name')
            . $this->iimDataset(2, 25, 'keyword-one')
            . $this->iimDataset(2, 25, 'keyword-two');

        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame(['Object Name'], $document->values(2, 5));
        self::assertSame(['keyword-one', 'keyword-two'], $document->values(2, 25));
    }

    /**
     * Uses IPTC extended-length encoding for dataset values.
     * This verifies that larger payloads are decoded correctly.
     */
    #[Test]
    public function parsesExtendedLengthDatasets(): void
    {
        $value   = str_repeat('A', 300);
        $iimData = $this->iimDatasetExtended(2, 120, $value, 2);
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame($value, $document->first(2, 120));
    }

    /**
     * Rejects extended-length datasets when length-byte-count is zero.
     */
    #[Test]
    public function rejectsExtendedLengthWithZeroLengthByteCount(): void
    {
        $iimData = $this->iimDatasetExtended(2, 120, 'A', 0);
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/extended length|length-byte-count|zero/i');

        (new IptcParser())->parse($payload);
    }

    /**
     * Rejects extended-length datasets with excessive length-byte-count values.
     */
    #[Test]
    public function rejectsExtendedLengthWithExcessiveLengthByteCount(): void
    {
        $iimData = $this->iimDatasetExtended(2, 120, 'A', 5);
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/extended length|length-byte-count|excessive/i');

        (new IptcParser())->parse($payload);
    }

    /**
     * Accepts odd-sized resource data when mandatory alignment padding is present.
     */
    #[Test]
    public function parsesOddSizedResourceDataWithTrailingPadByte(): void
    {
        $iimData = $this->iimDataset(2, 5, 'AB'); // 7 bytes (odd) including header
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame('AB', $document->first(2, 5));
    }

    /**
     * Accepts odd-length name/data fields when required alignment padding bytes are zero.
     */
    #[Test]
    public function parsesOddSizedNameAndDataWithZeroAlignmentPadding(): void
    {
        $iimData = $this->iimDataset(2, 5, 'AB'); // odd resource data length
        $block   = $this->resourceBlockWithExplicitPadding(0x0404, $iimData, 'AB');
        $payload = self::PHOTOSHOP_SIGNATURE . $block;

        $document = (new IptcParser())->parse($payload);

        self::assertSame('AB', $document->first(2, 5));
    }

    /**
     * Tolerates odd-length name fields when the alignment pad byte is non-zero.
     */
    #[Test]
    public function toleratesOddSizedNameWithNonZeroAlignmentPadding(): void
    {
        $iimData = $this->iimDataset(2, 5, 'AB');
        $block   = $this->resourceBlockWithExplicitPadding(0x0404, $iimData, 'AB', chr(1));
        $payload = self::PHOTOSHOP_SIGNATURE . $block;

        $document = (new IptcParser())->parse($payload);

        self::assertSame('AB', $document->first(2, 5));
    }

    /**
     * Tolerates odd-length resource data when the alignment pad byte is non-zero.
     */
    #[Test]
    public function toleratesOddSizedDataWithNonZeroAlignmentPadding(): void
    {
        $iimData = $this->iimDataset(2, 5, 'AB');
        $block   = $this->resourceBlockWithExplicitPadding(0x0404, $iimData, '', chr(0), chr(1));
        $payload = self::PHOTOSHOP_SIGNATURE . $block;

        $document = (new IptcParser())->parse($payload);

        self::assertSame('AB', $document->first(2, 5));
    }

    /**
     * Tolerates non-8BIM trailing data after valid resource blocks.
     */
    #[Test]
    public function toleratesNon8bimTrailingData(): void
    {
        $iimData  = $this->iimDataset(2, 5, 'Object Name');
        $block    = $this->resourceBlock(0x0404, $iimData);
        $trailing = 'XYZW' . str_repeat("\0", 20);
        $payload  = self::PHOTOSHOP_SIGNATURE . $block . $trailing;

        $document = (new IptcParser())->parse($payload);

        self::assertSame(['Object Name'], $document->values(2, 5));
    }

    /**
     * Keeps even-length APP13 resource blocks unaffected by alignment padding checks.
     */
    #[Test]
    public function parsesEvenSizedBlocksWithoutAlignmentPadding(): void
    {
        $iimData = $this->iimDataset(2, 5, 'A'); // even resource data length
        $block   = $this->resourceBlockWithExplicitPadding(0x0404, $iimData, 'A');
        $payload = self::PHOTOSHOP_SIGNATURE . $block;

        $document = (new IptcParser())->parse($payload);

        self::assertSame('A', $document->first(2, 5));
    }

    /**
     * Rejects odd-sized resource data when trailing alignment padding is missing.
     */
    #[Test]
    public function rejectsOddSizedResourceDataWithoutTrailingPadByte(): void
    {
        $iimData      = $this->iimDataset(2, 5, 'AB');
        $blockWithPad = $this->resourceBlock(0x0404, $iimData);
        $payload      = self::PHOTOSHOP_SIGNATURE . substr($blockWithPad, 0, -1);

        $this->expectException(BoundsError::class);
        $this->expectExceptionMessageMatches('/padding|pad byte|resource data/i');

        (new IptcParser())->parse($payload);
    }

    /**
     * Keeps even-sized resource data parsing unchanged without requiring padding.
     */
    #[Test]
    public function parsesEvenSizedResourceDataWithoutPaddingRequirement(): void
    {
        $iimData = $this->iimDataset(2, 5, 'A'); // 6 bytes (even) including header
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame('A', $document->first(2, 5));
    }

    /**
     * Stops scanning when a dataset marker byte is missing and returns partial results.
     * A valid dataset followed by non-IIM bytes should yield only the valid dataset.
     */
    #[Test]
    public function itStopsOnMissingDatasetMarkerAndReturnsPartialResults(): void
    {
        $validDataset = $this->iimDataset(2, 5, 'Object Name');
        $corruptTrail = "\xFF\x02\x19" . pack('n', 3) . 'XYZ';
        $iimData      = $validDataset . $corruptTrail;
        $payload      = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame(['Object Name'], $document->values(2, 5));
    }

    /**
     * Stops scanning when the remaining IIM bytes are too short for a dataset header.
     * A valid dataset followed by a truncated header should yield only the valid dataset.
     */
    #[Test]
    public function itStopsOnTruncatedDatasetHeaderAndReturnsPartialResults(): void
    {
        $validDataset   = $this->iimDataset(2, 5, 'Object Name');
        $truncatedBytes = "\x1C\x02";
        $iimData        = $validDataset . $truncatedBytes;
        $payload        = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame(['Object Name'], $document->values(2, 5));
    }

    /**
     * Parses all IPTC Application Record datasets used by the format script output.
     * This confirms that record 2 datasets round-trip through the parser with correct keys.
     */
    #[Test]
    public function parsesAllApplicationRecordDatasetsUsedByFormatScript(): void
    {
        $iimData = $this->iimDataset(2, 5, 'Test Object')
            . $this->iimDataset(2, 25, 'keyword-a')
            . $this->iimDataset(2, 25, 'keyword-b')
            . $this->iimDataset(2, 55, '20240315')
            . $this->iimDataset(2, 60, '143025+0100')
            . $this->iimDataset(2, 80, 'John Doe')
            . $this->iimDataset(2, 85, 'Photographer')
            . $this->iimDataset(2, 90, 'Berlin')
            . $this->iimDataset(2, 95, 'Brandenburg')
            . $this->iimDataset(2, 101, 'Germany')
            . $this->iimDataset(2, 105, 'A test headline')
            . $this->iimDataset(2, 110, 'Photo Agency')
            . $this->iimDataset(2, 115, 'Archive')
            . $this->iimDataset(2, 116, '(c) 2024 John Doe')
            . $this->iimDataset(2, 120, 'A detailed caption');

        $payload  = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);
        $document = (new IptcParser())->parse($payload);

        self::assertSame('Test Object', $document->first(2, 5));
        self::assertSame(['keyword-a', 'keyword-b'], $document->values(2, 25));
        self::assertSame('20240315', $document->first(2, 55));
        self::assertSame('143025+0100', $document->first(2, 60));
        self::assertSame('John Doe', $document->first(2, 80));
        self::assertSame('Photographer', $document->first(2, 85));
        self::assertSame('Berlin', $document->first(2, 90));
        self::assertSame('Brandenburg', $document->first(2, 95));
        self::assertSame('Germany', $document->first(2, 101));
        self::assertSame('A test headline', $document->first(2, 105));
        self::assertSame('Photo Agency', $document->first(2, 110));
        self::assertSame('Archive', $document->first(2, 115));
        self::assertSame('(c) 2024 John Doe', $document->first(2, 116));
        self::assertSame('A detailed caption', $document->first(2, 120));
    }

    /**
     * Truncates the Photoshop resource block to simulate corruption.
     * This asserts a BoundsError is thrown when the block length is inconsistent.
     */
    #[Test]
    public function rejectsTruncatedResourceBlocks(): void
    {
        $iimData = $this->iimDataset(2, 5, 'Title');
        $block   = $this->resourceBlock(0x0404, $iimData);

        $payload = self::PHOTOSHOP_SIGNATURE . substr($block, 0, -2);

        $this->expectException(BoundsError::class);

        (new IptcParser())->parse($payload);
    }

    private function resourceBlock(int $resourceId, string $data, string $name = ''): string
    {
        return $this->resourceBlockWithExplicitPadding($resourceId, $data, $name, "\0", "\0");
    }

    private function resourceBlockWithExplicitPadding(
        int $resourceId,
        string $data,
        string $name = '',
        string $namePaddingByte = "\0",
        string $dataPaddingByte = "\0",
    ): string {
        $nameLength = strlen($name);
        $nameField  = chr($nameLength) . $name;
        if ((strlen($nameField) % 2) !== 0) {
            $nameField .= $namePaddingByte;
        }

        $block = '8BIM'
            . pack('n', $resourceId)
            . $nameField
            . pack('N', strlen($data))
            . $data;

        if ((strlen($data) % 2) !== 0) {
            $block .= $dataPaddingByte;
        }

        return $block;
    }

    private function iimDataset(int $record, int $dataset, string $value): string
    {
        return "\x1C" . chr($record) . chr($dataset) . pack('n', strlen($value)) . $value;
    }

    private function iimDatasetExtended(int $record, int $dataset, string $value, int $lengthBytes): string
    {
        $length           = strlen($value);
        $lengthField      = 0x8000 | $lengthBytes;
        $lengthBytesValue = '';
        for ($index = $lengthBytes - 1; $index >= 0; --$index) {
            $shift = $index * 8;
            $lengthBytesValue .= chr(($length >> $shift) & 0xFF);
        }

        return "\x1C" . chr($record) . chr($dataset) . pack('n', $lengthField) . $lengthBytesValue . $value;
    }
}
