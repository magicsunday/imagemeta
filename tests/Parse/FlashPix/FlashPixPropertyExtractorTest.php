<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\FlashPix;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\ImageMeta\Parse\FlashPix\FlashPixPropertyExtractor;
use MagicSunday\ImageMeta\Parse\FlashPix\OlePropertySet;
use MagicSunday\ImageMeta\Value\FlashPixSummaryInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises FlashPix property extraction from OLE property sets.
 * Verifies Summary Information PID mapping to named fields.
 */
#[CoversClass(FlashPixPropertyExtractor::class)]
#[UsesClass(FlashPixSummaryInfo::class)]
#[UsesClass(OlePropertySet::class)]
final class FlashPixPropertyExtractorTest extends TestCase
{
    #[Test]
    public function extractsSummaryInfoProperties(): void
    {
        $set = new OlePropertySet(1252, [
            1  => 1252,
            2  => 'Photo Title',
            4  => 'John Doe',
            5  => 'landscape, nature',
            18 => 'FPX Camera App',
        ]);
        $extractor = new FlashPixPropertyExtractor();
        $info      = $extractor->extractSummaryInfo($set);

        self::assertInstanceOf(FlashPixSummaryInfo::class, $info);
        self::assertSame('Photo Title', $info->title);
        self::assertSame('John Doe', $info->author);
        self::assertSame('landscape, nature', $info->keywords);
        self::assertSame('FPX Camera App', $info->application);
        self::assertNull($info->subject);
        self::assertNull($info->comments);
    }

    #[Test]
    public function extractsSummaryInfoWithDateTimes(): void
    {
        $createTime = new DateTimeImmutable('2025-06-15T12:00:00', new DateTimeZone('UTC'));
        $set        = new OlePropertySet(1252, [
            1  => 1252,
            2  => 'Title',
            12 => $createTime,
        ]);
        $extractor = new FlashPixPropertyExtractor();
        $info      = $extractor->extractSummaryInfo($set);

        self::assertInstanceOf(FlashPixSummaryInfo::class, $info);
        self::assertSame($createTime, $info->createTime);
    }

    #[Test]
    public function returnsNullForEmptyPropertySet(): void
    {
        $set       = new OlePropertySet(1252, [1 => 1252]);
        $extractor = new FlashPixPropertyExtractor();

        self::assertNull($extractor->extractSummaryInfo($set));
    }

    #[Test]
    public function returnsNullWhenNoKnownSummaryPropertiesPresent(): void
    {
        $set       = new OlePropertySet(1252, [1 => 1252, 99 => 'unknown']);
        $extractor = new FlashPixPropertyExtractor();

        self::assertNull($extractor->extractSummaryInfo($set));
    }
}
