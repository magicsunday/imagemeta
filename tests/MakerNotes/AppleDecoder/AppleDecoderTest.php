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
     * Ensures the decoder returns the expected metadata map including vendor, length, and SHA1 hash.
     */
    #[Test]
    public function decodeReturnsExpectedMetadata(): void
    {
        $raw     = "\x01\x02example";
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertSame(['_vendor', '_length', '_sha1'], array_keys($metadata));
        self::assertSame('Apple', $metadata['_vendor']);
        self::assertSame(strlen($raw), $metadata['_length']);
        self::assertSame(40, strlen($metadata['_sha1']));
        self::assertSame(sha1($raw), $metadata['_sha1']);
    }
}
