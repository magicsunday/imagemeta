<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Exercises Nikon maker note decoding for vendor identity and payload metadata.
 * It verifies the decoder assigns the correct vendor name and computes payload hashes.
 * The suite checks that vendor-specific fields remain null for simple decoders.
 * This keeps Nikon maker note handling robust and predictable.
 */
#[CoversClass(NikonDecoder::class)]
#[UsesClass(MakerNotesRecord::class)]
final class NikonDecoderTest extends TestCase
{
    /**
     * Decodes a synthetic Nikon maker note payload.
     * Verifies the decoder produces the correct vendor name, payload length, and SHA-1 digest.
     */
    #[Test]
    public function decodeReturnsNikonVendorWithPayloadMetadata(): void
    {
        $raw = "Nikon\x00\x02\x10\x00\x00MakerNote\x00payload";

        $decoder = new NikonDecoder();
        $record  = $decoder->decode($raw, 'Nikon Corporation', 'D850');

        self::assertSame('Nikon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
        self::assertNull($record->apple);
        self::assertNull($record->samsung);
    }

    /**
     * Decodes a payload using an alternate make string that differs from the vendor name.
     * Verifies the decoder still reports "Nikon" regardless of the make parameter value.
     */
    #[Test]
    public function decodeUsesFixedVendorNameRegardlessOfMakeString(): void
    {
        $raw = "\x00\x01\x02\x03\x04\x05\x06\x07";

        $decoder = new NikonDecoder();
        $record  = $decoder->decode($raw, 'NIKON CORPORATION', null);

        self::assertSame('Nikon', $record->vendor);
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
        $raw = "\xFF";

        $decoder = new NikonDecoder();
        $record  = $decoder->decode($raw, 'Nikon', null);

        self::assertSame('Nikon', $record->vendor);
        self::assertSame(1, $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }
}
