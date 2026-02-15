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
use MagicSunday\ImageMeta\Parse\Tiff\ExifTagDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ASCII payload decoding rules for TIFF/EXIF tag values.
 *
 * @internal
 */
#[CoversClass(ExifTagDecoder::class)]
final class ExifTagDecoderTest extends TestCase
{
    #[Test]
    public function decodesSevenBitAsciiPayload(): void
    {
        $decoder = new ExifTagDecoder();

        self::assertSame('Camera', $decoder->decodeAscii(1, 7, "Camera\0", []));
    }

    #[Test]
    public function decodesUtf8PayloadForWhitelistedTag(): void
    {
        $decoder = new ExifTagDecoder();

        self::assertSame('Jörg', $decoder->decodeAscii(700, 6, "Jörg\0", [700]));
    }

    #[Test]
    public function rejectsNonSevenBitAsciiForNonWhitelistedTag(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('ASCII value contains non-7-bit byte');

        $decoder = new ExifTagDecoder();
        $decoder->decodeAscii(701, 6, "Jörg\0", [700]);
    }
}
