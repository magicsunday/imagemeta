<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\NikonDecoder;

use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Validates the Nikon maker notes decoder implementation.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\NikonDecoder
 */
final class NikonDecoderTest extends TestCase
{
    /**
     * Ensures the decoder returns consistent metadata for a Nikon-style maker note payload.
     */
    #[Test]
    public function decodeReturnsExpectedMetadata(): void
    {
        $raw     = "Nikon\x00\x02\x00\x08\x00\x00\x01";
        $decoder = new NikonDecoder();

        $metadata = $decoder->decode($raw, 'Nikon Corporation', 'NIKON Z 8');

        self::assertSame('Nikon', $metadata->vendor());
        self::assertSame(strlen($raw), $metadata->length());
        self::assertSame(sha1($raw), $metadata->sha1());
    }
}
