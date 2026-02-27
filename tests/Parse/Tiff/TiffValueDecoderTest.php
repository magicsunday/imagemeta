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
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Parse\Tiff\ExifTagDecoder;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffByteOrderHandler;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function str_repeat;

/**
 * Verifies TIFF value decoding with precomputed component size / byte-count hints.
 *
 * @internal
 */
#[CoversClass(TiffValueDecoder::class)]
final class TiffValueDecoderTest extends TestCase
{
    #[Test]
    public function exposesPrecomputedHintParametersInPublicMethods(): void
    {
        $valueBytes = new ReflectionMethod(TiffValueDecoder::class, 'valueBytes');
        self::assertCount(6, $valueBytes->getParameters());
        self::assertSame('componentSize', $valueBytes->getParameters()[4]->getName());
        self::assertSame('valueBytes', $valueBytes->getParameters()[5]->getName());

        $decodeBytes = new ReflectionMethod(TiffValueDecoder::class, 'decodeBytes');
        self::assertCount(6, $decodeBytes->getParameters());
        self::assertSame('componentSize', $decodeBytes->getParameters()[4]->getName());
        self::assertSame('expectedBytes', $decodeBytes->getParameters()[5]->getName());
    }

    #[Test]
    public function valueBytesAcceptsPrecomputedSizeAndLength(): void
    {
        $decoder = $this->createDecoder();

        [$rawBytes, $offset] = $decoder->valueBytes(
            TiffConst::TYPE_SHORT,
            1,
            "\x34\x12",
            null,
            2,
            2,
        );

        self::assertSame("\x34\x12", $rawBytes);
        self::assertNull($offset);
    }

    #[Test]
    public function decodeBytesAcceptsPrecomputedSizeAndLength(): void
    {
        $decoder = $this->createDecoder();

        $value = $decoder->decodeBytes(
            ExifTag::IMAGE_WIDTH,
            TiffConst::TYPE_SHORT,
            1,
            "\x34\x12",
            2,
            2,
        );

        self::assertSame(0x1234, $value);
    }

    #[Test]
    public function decodeBytesRejectsTruncatedPayloadWithPrecomputedLength(): void
    {
        $this->expectException(ParseError::class);

        $decoder = $this->createDecoder();
        $decoder->decodeBytes(
            ExifTag::IMAGE_WIDTH,
            TiffConst::TYPE_SHORT,
            2,
            "\x34\x12",
            2,
            4,
        );
    }

    private function createDecoder(): TiffValueDecoder
    {
        $buffer          = new MemoryBuffer(str_repeat("\0", 128));
        $byteOrder       = Endian::Little;
        $byteOrderHelper = new TiffByteOrderHandler();
        $binaryReader    = new TiffBinaryReader($buffer, $byteOrder, $byteOrderHelper, false, 8);
        $offsetValidator = new TiffOffsetValidator($buffer, UInt64::fromInt($buffer->size()));

        return new TiffValueDecoder(
            $binaryReader,
            $offsetValidator,
            new ExifTagDecoder(),
        );
    }
}
