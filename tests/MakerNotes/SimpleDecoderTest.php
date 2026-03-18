<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\SimpleDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Exercises the SimpleDecoder for vendor identity and payload metadata.
 * It verifies the decoder assigns the correct vendor name and computes payload hashes.
 * The suite checks that vendor-specific fields remain null for simple decoders.
 * This keeps simple maker note handling robust and predictable.
 */
#[CoversClass(SimpleDecoder::class)]
#[UsesClass(MakerNotesRecord::class)]
final class SimpleDecoderTest extends TestCase
{
    /**
     * Verifies the decode implementation hashes payload bytes consistently.
     */
    #[Test]
    public function decodeComputesRecordFromPayloadUsingVendorName(): void
    {
        $raw     = "test\0payload";

        $decoder = new SimpleDecoder('Acme');

        $record  = $decoder->decode($raw, 'Acme Camera Co', 'Model 1');

        self::assertSame('Acme', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * Confirms built-in simple decoders produce the correct vendor name.
     *
     * @param string                     $vendor  Expected vendor name in the decoded record.
     * @param MakerNotesDecoderInterface $decoder Decoder under test.
     */
    #[Test]
    #[DataProvider('provideSimpleDecoders')]
    public function builtInSimpleDecodersProduceCorrectVendorName(string $vendor, MakerNotesDecoderInterface $decoder): void
    {
        $raw    = "maker-note\0payload";
        $record = $decoder->decode($raw, $vendor, null);

        self::assertInstanceOf(SimpleDecoder::class, $decoder);
        self::assertSame($vendor, $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * Decodes a synthetic Canon maker note payload.
     * Verifies the decoder produces the correct vendor name, payload length, and SHA-1 digest.
     */
    #[Test]
    public function decodeReturnsCanonVendorWithPayloadMetadata(): void
    {
        $raw     = "Canon\x00MakerNote\x00payload\x01\x02\x03";

        $decoder = new SimpleDecoder('Canon');
        $record  = $decoder->decode($raw, 'Canon', 'EOS R5');

        self::assertSame('Canon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
        self::assertNull($record->apple);
        self::assertNull($record->samsung);
    }

    /**
     * Decodes a payload using an alternate make string that differs from the vendor name.
     * Verifies the decoder still reports the configured vendor regardless of the make parameter value.
     */
    #[Test]
    public function decodeUsesFixedVendorNameRegardlessOfMakeString(): void
    {
        $raw     = "\x00\x01\x02\x03\x04\x05\x06\x07";

        $decoder = new SimpleDecoder('Canon');
        $record  = $decoder->decode($raw, 'Canon Inc.', null);

        self::assertSame('Canon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * Decodes a synthetic Nikon maker note payload.
     * Verifies the decoder produces the correct vendor name, payload length, and SHA-1 digest.
     */
    #[Test]
    public function decodeReturnsNikonVendorWithPayloadMetadata(): void
    {
        $raw     = "Nikon\x00\x02\x10\x00\x00MakerNote\x00payload";

        $decoder = new SimpleDecoder('Nikon');
        $record  = $decoder->decode($raw, 'Nikon Corporation', 'D850');

        self::assertSame('Nikon', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
        self::assertNull($record->apple);
        self::assertNull($record->samsung);
    }

    /**
     * Decodes a synthetic Sony maker note payload.
     * Verifies the decoder produces the correct vendor name, payload length, and SHA-1 digest.
     */
    #[Test]
    public function decodeReturnsSonyVendorWithPayloadMetadata(): void
    {
        $raw     = "SONY DSC \x00\x01\x02MakerNote\x00payload";

        $decoder = new SimpleDecoder('Sony');
        $record  = $decoder->decode($raw, 'Sony Corporation', 'ILCE-7RM5');

        self::assertSame('Sony', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
        self::assertNull($record->apple);
        self::assertNull($record->samsung);
    }

    /**
     * Decodes a minimal single-byte payload.
     * Ensures the decoder handles short payloads without errors.
     */
    #[Test]
    public function decodeHandlesMinimalPayload(): void
    {
        $raw     = "\x00";

        $decoder = new SimpleDecoder('Canon');
        $record  = $decoder->decode($raw, 'Canon', null);

        self::assertSame('Canon', $record->vendor);
        self::assertSame(1, $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * @return iterable<string, array{0:string, 1:MakerNotesDecoderInterface}>
     */
    public static function provideSimpleDecoders(): iterable
    {
        yield 'canon' => ['Canon', new SimpleDecoder('Canon')];
        yield 'nikon' => ['Nikon', new SimpleDecoder('Nikon')];
        yield 'sony' => ['Sony', new SimpleDecoder('Sony')];
    }
}
