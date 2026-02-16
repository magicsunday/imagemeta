<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

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
    public function decodesUtf8InNonDesignatedAsciiTag(): void
    {
        $decoder = new ExifTagDecoder();

        // 'ö' is UTF-8 \xC3\xB6 — accepted even for non-whitelisted tags
        self::assertSame('Jörg', $decoder->decodeAscii(701, 6, "Jörg\0", [700]));
    }

    #[Test]
    public function decodesLatin1AsciiAsFallback(): void
    {
        $decoder = new ExifTagDecoder();

        // 0xE9 = 'é' in Latin-1, not valid single-byte UTF-8
        self::assertSame('Renée', $decoder->decodeAscii(0x010F, 6, "Ren\xE9e\0", []));
    }
}
