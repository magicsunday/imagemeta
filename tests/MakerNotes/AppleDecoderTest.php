<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use function sha1;
use function strlen;

/**
 * Tests the Apple maker notes decoder.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderTest extends TestCase
{
    /**
     * Ensures the decoder returns the expected vendor information, payload length, and hash.
     */
    #[Test]
    public function returnsExpectedMetadata(): void
    {
        $raw = "\x01\x02\x03custom payload";
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertSame('Apple', $metadata['_vendor']);
        self::assertSame(strlen($raw), $metadata['_length']);
        self::assertSame(sha1($raw, false), $metadata['_sha1']);
    }
}
