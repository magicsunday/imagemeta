<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\CanonDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Validates the Canon maker notes decoder implementation.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\CanonDecoder
 */
final class CanonDecoderTest extends TestCase
{
    /**
     * Ensures the decoder returns consistent metadata for a Canon-style maker note payload.
     */
    #[Test]
    public function decodeReturnsExpectedMetadata(): void
    {
        $raw     = "Canon\x00\x01\x00\x10\x00\x02\x00";
        $decoder = new CanonDecoder();

        $metadata = $decoder->decode($raw, 'Canon', 'Canon EOS R5');

        self::assertSame('Canon', $metadata->vendor);
        self::assertSame(strlen($raw), $metadata->length);
        self::assertSame(sha1($raw), $metadata->sha1);
    }
}
