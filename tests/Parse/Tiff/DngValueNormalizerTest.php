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
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
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
 * Verifies counted strip/tile value decoding with precomputed size hints.
 *
 * @internal
 */
#[CoversClass(DngValueNormalizer::class)]
final class DngValueNormalizerTest extends TestCase
{
    #[Test]
    public function exposesPrecomputedHintParametersForCountedImageDataNormalization(): void
    {
        $method = new ReflectionMethod(DngValueNormalizer::class, 'normalizeCountedImageDataField');

        self::assertCount(6, $method->getParameters());
        self::assertSame('componentSize', $method->getParameters()[4]->getName());
        self::assertSame('expectedLength', $method->getParameters()[5]->getName());
    }

    #[Test]
    public function normalizesCountedImageDataWithPrecomputedLengths(): void
    {
        $normalizer = $this->createNormalizer();

        $value      = $normalizer->normalizeCountedImageDataField(
            ExifTag::STRIP_BYTE_COUNTS,
            TiffConst::TYPE_SHORT,
            2,
            "\x01\x00\x02\x00",
            2,
            4,
        );

        self::assertInstanceOf(ExifNumericList::class, $value);
        self::assertSame([1, 2], $value->values);
    }

    #[Test]
    public function rejectsTruncatedCountedImageDataWithPrecomputedLength(): void
    {
        $this->expectException(ParseError::class);

        $normalizer = $this->createNormalizer();
        $normalizer->normalizeCountedImageDataField(
            TiffTag::TILE_BYTE_COUNTS,
            TiffConst::TYPE_SHORT,
            2,
            "\x01\x00",
            2,
            4,
        );
    }

    private function createNormalizer(): DngValueNormalizer
    {
        $buffer          = new MemoryBuffer(str_repeat("\0", 128));
        $byteOrder       = Endian::Little;
        $byteOrderHelper = new TiffByteOrderHandler();
        $binaryReader    = new TiffBinaryReader($buffer, $byteOrder, $byteOrderHelper, false, 8);
        $offsetValidator = new TiffOffsetValidator($buffer, UInt64::fromInt($buffer->size()));
        $decoder         = new TiffValueDecoder($binaryReader, $offsetValidator, new ExifTagDecoder());

        return new DngValueNormalizer($binaryReader, $offsetValidator, $decoder);
    }
}
