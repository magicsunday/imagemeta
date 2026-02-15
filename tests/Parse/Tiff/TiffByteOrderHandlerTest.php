<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Util\UInt64;
use MagicSunday\ImageMeta\Parse\Tiff\TiffByteOrderHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies endian-dependent primitive reads and integer byte conversion helpers.
 *
 * @internal
 */
#[CoversClass(TiffByteOrderHandler::class)]
final class TiffByteOrderHandlerTest extends TestCase
{
    #[Test]
    public function readsUnsignedIntegersForBothByteOrders(): void
    {
        $handler = new TiffByteOrderHandler();

        $little16 = new MemoryBuffer("\x34\x12");
        self::assertSame(0x1234, $handler->readUint16($little16, Endian::Little));

        $big16 = new MemoryBuffer("\x12\x34");
        self::assertSame(0x1234, $handler->readUint16($big16, Endian::Big));

        $little32 = new MemoryBuffer("\x78\x56\x34\x12");
        self::assertSame(0x12345678, $handler->readUint32($little32, Endian::Little));

        $big32 = new MemoryBuffer("\x12\x34\x56\x78");
        self::assertSame(0x12345678, $handler->readUint32($big32, Endian::Big));

        $little64 = new MemoryBuffer("\x08\x07\x06\x05\x04\x03\x02\x01");
        self::assertSame(0x0102030405060708, $handler->readUint64($little64, Endian::Little)->toInt('little 64'));

        $big64 = new MemoryBuffer("\x01\x02\x03\x04\x05\x06\x07\x08");
        self::assertSame(0x0102030405060708, $handler->readUint64($big64, Endian::Big)->toInt('big 64'));
    }

    #[Test]
    public function convertsUnsignedIntegersToEndianAwareBytes(): void
    {
        $handler = new TiffByteOrderHandler();

        self::assertSame("\x78\x56\x34\x12", $handler->uintToBytes(0x12345678, 4, Endian::Little));
        self::assertSame("\x12\x34\x56\x78", $handler->uintToBytes(0x12345678, 4, Endian::Big));

        $value64 = UInt64::fromInt(0x0102030405060708);
        self::assertSame("\x08\x07\x06\x05\x04\x03\x02\x01", $handler->uintToBytes($value64, 8, Endian::Little));
        self::assertSame("\x01\x02\x03\x04\x05\x06\x07\x08", $handler->uintToBytes($value64, 8, Endian::Big));
    }

    #[Test]
    public function rejectsUnsupportedByteWidthConversion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('unsupported integer width for byte conversion');

        $handler = new TiffByteOrderHandler();
        $handler->uintToBytes(1, 2, Endian::Little);
    }
}
