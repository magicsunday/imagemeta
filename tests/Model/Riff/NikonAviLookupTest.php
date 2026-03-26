<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Riff;

use MagicSunday\ImageMeta\Model\Riff\NikonAviLookup;
use MagicSunday\ImageMeta\Model\Riff\NikonCameraTags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NikonAviLookup::class)]
#[UsesClass(NikonCameraTags::class)]
final class NikonAviLookupTest extends TestCase
{
    #[Test]
    public function returnsTypedFieldsFromNikonCameraTags(): void
    {
        $tags = new NikonCameraTags(
            entries: [],
            make: 'NIKON',
            model: 'P80',
            software: 'COOLPIX P80V1.1',
            exposureTime: 0.0334,
            fNumber: 2.8,
            focalLength: 4.7,
            dateTimeOriginal: '2009:12:25 00:15:52',
            createDate: '2009:12:25 00:15:52',
        );
        $lookup = new NikonAviLookup($tags);

        self::assertSame('NIKON', $lookup->make());
        self::assertSame('P80', $lookup->model());
        self::assertSame('COOLPIX P80V1.1', $lookup->software());
        self::assertSame('2009:12:25 00:15:52', $lookup->dateTimeOriginal());
        self::assertSame('2009:12:25 00:15:52', $lookup->createDate());
        self::assertEqualsWithDelta(2.8, $lookup->fNumber(), 0.001);
        self::assertEqualsWithDelta(4.7, $lookup->focalLength(), 0.001);
    }

    #[Test]
    public function returnsNullWhenTagsAbsent(): void
    {
        $lookup = new NikonAviLookup(null);

        self::assertNull($lookup->make());
        self::assertNull($lookup->model());
        self::assertNull($lookup->dateTimeOriginal());
        self::assertNull($lookup->fNumber());
    }
}
