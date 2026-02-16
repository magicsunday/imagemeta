<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Parse\Tiff\IfdParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates ordering and duplicate constraints for extracted IFD entry coordination.
 *
 * @internal
 */
#[CoversClass(IfdParser::class)]
final class IfdParserTest extends TestCase
{
    #[Test]
    public function addsEntriesWithAscendingTagOrder(): void
    {
        $parser = new IfdParser();
        $entry1 = new IfdEntry(0x0100, 4, 1, 10);
        $entry2 = new IfdEntry(0x0101, 4, 1, 20);

        $entries   = [];
        $lastTagId = null;

        $parser->addEntry($entries, $lastTagId, $entry1);
        $parser->addEntry($entries, $lastTagId, $entry2);

        self::assertSame([0x0100, 0x0101], array_keys($entries));
        self::assertSame(0x0101, $lastTagId);
    }

    #[Test]
    public function acceptsUnsortedIfdEntries(): void
    {
        $parser = new IfdParser();
        $entry1 = new IfdEntry(0x0112, 3, 1, 1);
        $entry2 = new IfdEntry(0x010F, 2, 5, 'Test');

        $entries   = [];
        $lastTagId = null;

        $parser->addEntry($entries, $lastTagId, $entry1);
        $parser->addEntry($entries, $lastTagId, $entry2);

        self::assertCount(2, $entries);
        self::assertArrayHasKey(0x010F, $entries);
        self::assertArrayHasKey(0x0112, $entries);
    }

    #[Test]
    public function rejectsDuplicateEntryTags(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Duplicate tag ID');

        $parser = new IfdParser();
        $entry1 = new IfdEntry(0x0100, 4, 1, 10);
        $entry2 = new IfdEntry(0x0100, 4, 1, 20);

        $entries   = [];
        $lastTagId = null;

        $parser->addEntry($entries, $lastTagId, $entry1);
        $parser->addEntry($entries, $lastTagId, $entry2);
    }
}
