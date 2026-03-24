<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core\Util;

use MagicSunday\ImageMeta\Core\Util\DateTimeUtil;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateTimeUtil::class)]
final class DateTimeUtilTest extends TestCase
{
    #[Test]
    public function parsesCtimeFormat(): void
    {
        $result = DateTimeUtil::parseRiffDate('Mon Dec 15 15:19:38 2014');

        self::assertNotNull($result);
        self::assertSame('2014-12-15 15:19:38', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function parsesCtimeWithSingleDigitDay(): void
    {
        $result = DateTimeUtil::parseRiffDate('Wed Jul  5 10:46:25 2017');

        self::assertNotNull($result);
        self::assertSame('2017-07-05 10:46:25', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function parsesCtimeWithNonStandardDayAbbreviation(): void
    {
        // Some cameras write "Wen" instead of "Wed"
        $result = DateTimeUtil::parseRiffDate('Wen Jul  5 10:46:25 2017');

        self::assertNotNull($result);
        self::assertSame('2017-07-05 10:46:25', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function parsesExifColonFormat(): void
    {
        $result = DateTimeUtil::parseRiffDate('2024:03:15 10:30:00');

        self::assertNotNull($result);
        self::assertSame('2024-03-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function parsesIsoDashFormat(): void
    {
        $result = DateTimeUtil::parseRiffDate('2002-12-16 15:35:01');

        self::assertNotNull($result);
        self::assertSame('2002-12-16 15:35:01', $result->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function returnsNullForNullInput(): void
    {
        self::assertNull(DateTimeUtil::parseRiffDate(null));
    }

    #[Test]
    public function returnsNullForEmptyString(): void
    {
        self::assertNull(DateTimeUtil::parseRiffDate(''));
    }

    #[Test]
    public function returnsNullForUnparseableString(): void
    {
        self::assertNull(DateTimeUtil::parseRiffDate('not a date'));
    }
}
