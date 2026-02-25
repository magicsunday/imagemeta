<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Icc;

use MagicSunday\ImageMeta\Parse\Icc\IccBinaryReader;
use MagicSunday\ImageMeta\Parse\Icc\IccTagDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_pad;
use function str_repeat;

/**
 * Tests IccTagDecoder tag data size alignment handling.
 *
 * ICC.1:2022 §7.3 — tag offsets must be 4-byte aligned, but tag sizes
 * are actual byte counts and need not be multiples of 4.
 *
 * @internal
 */
#[CoversClass(IccTagDecoder::class)]
#[UsesClass(IccBinaryReader::class)]
final class IccTagDecoderTest extends TestCase
{
    /**
     * Tag with size not divisible by 4 is correctly decoded.
     *
     * ICC.1:2022 §7.3 — tag data size is the actual byte count, not padded.
     */
    #[Test]
    public function decodesTagWithNonAlignedSize(): void
    {
        // Build a synthetic ICC profile with a single 'cprt' tag using textType
        // with a non-4-byte-aligned size (13 bytes: 'text' + 4 reserved + 'Hi!\0' = 12,
        // but we use 13 to be non-aligned)
        $tagPayload = "text"                    // type signature
            . "\x00\x00\x00\x00"                // reserved
            . "Hello\x00";                      // ASCII text with NUL terminator

        // tagPayload is 14 bytes (not a multiple of 4)
        $profile = $this->buildProfile('cprt', $tagPayload);

        $decoder = new IccTagDecoder(new IccBinaryReader());
        $result  = $decoder->extractTag($profile, strlen($profile), 'cprt', 2);

        self::assertSame('Hello', $result);
    }

    /**
     * Tag with 4-byte-aligned size continues to work.
     *
     * ICC.1:2022 §7.3 — aligned sizes must remain functional.
     */
    #[Test]
    public function decodesTagWithAlignedSize(): void
    {
        $tagPayload = "text"                    // type signature
            . "\x00\x00\x00\x00"                // reserved
            . "OK!\x00";                        // 4 bytes: ASCII text with NUL

        // tagPayload is 12 bytes (multiple of 4)
        $profile = $this->buildProfile('cprt', $tagPayload);

        $decoder = new IccTagDecoder(new IccBinaryReader());
        $result  = $decoder->extractTag($profile, strlen($profile), 'cprt', 2);

        self::assertSame('OK!', $result);
    }

    /**
     * Builds a minimal synthetic ICC profile with one tag.
     *
     * @param string $signature  4-byte tag signature.
     * @param string $tagPayload Raw tag payload.
     */
    private function buildProfile(string $signature, string $tagPayload): string
    {
        // 128-byte header (mostly zeroes)
        $header = str_pad('', 128, "\x00");

        // Tag count = 1
        $tagCount = pack('N', 1);

        // Tag table entry: signature (4) + offset (4) + size (4)
        // Offset = 128 (header) + 4 (tag count) + 12 (one tag record) = 144
        $tagOffset = 128 + 4 + 12;
        $tagSize   = strlen($tagPayload);
        $tagRecord = $signature . pack('N', $tagOffset) . pack('N', $tagSize);

        // Pad tag offset to 4-byte alignment (offset 144 is already aligned)
        $profile = $header . $tagCount . $tagRecord . $tagPayload;

        // Overwrite profile size field at offset 0 (4 bytes BE)
        $profileSize = strlen($profile);
        $profile[0]  = pack('N', $profileSize)[0];
        $profile[1]  = pack('N', $profileSize)[1];
        $profile[2]  = pack('N', $profileSize)[2];
        $profile[3]  = pack('N', $profileSize)[3];

        return $profile;
    }
}
