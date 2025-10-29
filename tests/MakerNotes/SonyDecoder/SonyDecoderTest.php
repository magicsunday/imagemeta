<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\SonyDecoder;

use MagicSunday\ImageMeta\MakerNotes\SonyDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Validates the Sony maker notes decoder implementation.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\SonyDecoder
 */
final class SonyDecoderTest extends TestCase
{
    /**
     * Ensures the decoder returns consistent metadata for a Sony-style maker note payload.
     */
    #[Test]
    public function decodeReturnsExpectedMetadata(): void
    {
        $raw     = "SONY\x00\x03\x00\x12\x00\x04";
        $decoder = new SonyDecoder();

        $metadata = $decoder->decode($raw, 'Sony', 'ILCE-7RM5');

        self::assertSame('Sony', $metadata->vendor());
        self::assertSame(strlen($raw), $metadata->length());
        self::assertSame(sha1($raw), $metadata->sha1());
    }
}
