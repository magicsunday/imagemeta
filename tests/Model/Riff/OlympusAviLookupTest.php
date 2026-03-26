<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Riff;

use MagicSunday\ImageMeta\Model\Riff\OlympusAviLookup;
use MagicSunday\ImageMeta\Model\Riff\OlympusCameraTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OlympusAviLookup::class)]
#[UsesClass(OlympusCameraTags::class)]
final class OlympusAviLookupTest extends TestCase
{
    #[Test]
    public function returnsTypedFields(): void
    {
        $tags = new OlympusCameraTags(
            entries: [],
            make: 'OLYMPUS',
            model: 'FE120,X700',
            fNumber: 2.8,
            dateTime1: 'Wed Jan 07 20:37:36 2009',
            dateTime2: 'Wed Jan 07 20:37:36 2009',
        );
        $lookup = new OlympusAviLookup($tags);

        self::assertSame('OLYMPUS', $lookup->make());
        self::assertSame('FE120,X700', $lookup->model());
        self::assertEqualsWithDelta(2.8, $lookup->fNumber(), 0.001);
        self::assertSame('Wed Jan 07 20:37:36 2009', $lookup->dateTime1());
        self::assertSame('Wed Jan 07 20:37:36 2009', $lookup->dateTime2());
    }

    #[Test]
    public function returnsNullWhenAbsent(): void
    {
        $lookup = new OlympusAviLookup(null);

        self::assertNull($lookup->make());
        self::assertNull($lookup->model());
        self::assertNull($lookup->fNumber());
        self::assertNull($lookup->dateTime1());
        self::assertNull($lookup->dateTime2());
    }
}
