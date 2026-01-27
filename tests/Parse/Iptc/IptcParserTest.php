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

#[CoversClass(IptcParser::class)]
#[UsesClass(IptcDocument::class)]
final class IptcParserTest extends TestCase
{
    private const string PHOTOSHOP_SIGNATURE = "Photoshop 3.0\0";

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

    #[Test]
    public function parsesExtendedLengthDatasets(): void
    {
        $value   = str_repeat('A', 300);
        $iimData = $this->iimDatasetExtended(2, 120, $value, 2);
        $payload = self::PHOTOSHOP_SIGNATURE . $this->resourceBlock(0x0404, $iimData);

        $document = (new IptcParser())->parse($payload);

        self::assertSame($value, $document->first(2, 120));
    }

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
