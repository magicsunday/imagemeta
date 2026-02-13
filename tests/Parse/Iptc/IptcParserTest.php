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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
     * Rejects odd-sized resource data when trailing alignment padding is missing.
     *
     * @return void
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
     *
     * @return void
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
     * Truncates the Photoshop resource block to simulate corruption.
     * This asserts a BoundsError is thrown when the block length is inconsistent.
     *
     * @return void
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
        $nameLength = strlen($name);
        $nameField  = chr($nameLength) . $name;
        if ((strlen($nameField) % 2) !== 0) {
            $nameField .= "\0";
        }

        $block = '8BIM'
            . pack('n', $resourceId)
            . $nameField
            . pack('N', strlen($data))
            . $data;

        if ((strlen($data) % 2) !== 0) {
            $block .= "\0";
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
