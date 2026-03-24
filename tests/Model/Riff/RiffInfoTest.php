<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Riff;

use MagicSunday\ImageMeta\Model\Riff\RiffExifChunk;
use MagicSunday\ImageMeta\Model\Riff\RiffInfo;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiffInfo::class)]
#[CoversClass(RiffInfoLookup::class)]
final class RiffInfoTest extends TestCase
{
    #[Test]
    public function storesAndRetrievesInfoFields(): void
    {
        $info = new RiffInfo(['INAM' => 'My Video', 'IART' => 'Author']);

        self::assertSame('My Video', $info->get('INAM'));
        self::assertSame('Author', $info->get('IART'));
        self::assertNull($info->get('ICMT'));
    }

    #[Test]
    public function returnsNullForAbsentTag(): void
    {
        $info = new RiffInfo([]);

        self::assertNull($info->get('INAM'));
    }

    #[Test]
    public function lookupReturnsFirstMatchingInfoField(): void
    {
        $info   = new RiffInfo(['ISFT' => 'Encoder v1']);
        $lookup = new RiffInfoLookup($info, null);

        self::assertSame('Encoder v1', $lookup->string('ISFT'));
        self::assertNull($lookup->string('INAM'));
    }

    #[Test]
    public function lookupReturnsNullWhenInfoAndExifAbsent(): void
    {
        $lookup = new RiffInfoLookup(null, null);

        self::assertNull($lookup->string('INAM'));
    }

    #[Test]
    public function lookupReturnsExifMakeAndModel(): void
    {
        $riffExif = new RiffExifChunk(make: 'Canon', model: 'EOS R5');
        $lookup   = new RiffInfoLookup(null, $riffExif);

        self::assertSame('Canon', $lookup->exifMake());
        self::assertSame('EOS R5', $lookup->exifModel());
        self::assertNull($lookup->exifTimeCreated());
    }

    #[Test]
    public function lookupReturnsExifTimeCreated(): void
    {
        $riffExif = new RiffExifChunk(timeCreated: '2024:03:15 10:30:00');
        $lookup   = new RiffInfoLookup(null, $riffExif);

        self::assertSame('2024:03:15 10:30:00', $lookup->exifTimeCreated());
    }
}
