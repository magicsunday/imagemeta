<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\AppleDecoder;

use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Validates the Apple maker notes decoder implementation.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderTest extends TestCase
{
    /**
     * Ensures the decoder returns the expected metadata value object including vendor, length, and SHA-1 hash.
     */
    #[Test]
    public function decodeReturnsExpectedMetadata(): void
    {
        $raw     = "\x01\x02example";
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);
        self::assertSame('Apple', $metadata->vendor());
        self::assertSame(strlen($raw), $metadata->length());
        self::assertSame(sha1($raw), $metadata->sha1());
    }
}
