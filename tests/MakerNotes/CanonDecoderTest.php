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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Exercises Canon maker note decoding for vendor identity and payload metadata.
 * It verifies the decoder assigns the correct vendor name and computes payload hashes.
 * The suite checks that vendor-specific fields remain null for simple decoders.
 * This keeps Canon maker note handling robust and predictable.
 */
#[CoversClass(CanonDecoder::class)]
#[UsesClass(MakerNotesRecord::class)]
final class CanonDecoderTest extends TestCase
{
    /**
     * Decodes a synthetic Canon maker note payload.
     * Verifies the decoder produces the correct vendor name, payload length, and SHA-1 digest.
     */
    #[Test]
    public function decodeReturnsCanonVendorWithPayloadMetadata(): void
    {
        $raw = "Canon\x00MakerNote\x00payload\x01\x02\x03";

        $decoder = new CanonDecoder();
        $record  = $decoder->decode($raw, 'Canon', 'EOS R5');

        self::assertSame('Canon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
        self::assertNull($record->apple);
        self::assertNull($record->samsung);
    }

    /**
     * Decodes a payload using an alternate make string that differs from the vendor name.
     * Verifies the decoder still reports "Canon" regardless of the make parameter value.
     */
    #[Test]
    public function decodeUsesFixedVendorNameRegardlessOfMakeString(): void
    {
        $raw = "\x00\x01\x02\x03\x04\x05\x06\x07";

        $decoder = new CanonDecoder();
        $record  = $decoder->decode($raw, 'Canon Inc.', null);

        self::assertSame('Canon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * Decodes a minimal single-byte payload.
     * Ensures the decoder handles short payloads without errors.
     */
    #[Test]
    public function decodeHandlesMinimalPayload(): void
    {
        $raw = "\x00";

        $decoder = new CanonDecoder();
        $record  = $decoder->decode($raw, 'Canon', null);

        self::assertSame('Canon', $record->vendor);
        self::assertSame(1, $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }
}
