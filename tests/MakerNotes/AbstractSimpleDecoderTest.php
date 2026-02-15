<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\AbstractSimpleDecoder;
use MagicSunday\ImageMeta\MakerNotes\CanonDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use MagicSunday\ImageMeta\MakerNotes\SonyDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sha1;
use function strlen;

/**
 * Exercises shared behavior for simple maker note decoders.
 */
#[CoversClass(AbstractSimpleDecoder::class)]
#[UsesClass(CanonDecoder::class)]
#[UsesClass(NikonDecoder::class)]
#[UsesClass(SonyDecoder::class)]
final class AbstractSimpleDecoderTest extends TestCase
{
    /**
     * Verifies the shared decode implementation hashes payload bytes consistently.
     *
     * @return void
     */
    #[Test]
    public function decodeComputesRecordFromPayloadUsingVendorName(): void
    {
        $raw = "test\0payload";

        $decoder = new class extends AbstractSimpleDecoder {
            protected function getVendorName(): string
            {
                return 'Acme';
            }
        };

        $record = $decoder->decode($raw, 'Acme Camera Co', 'Model 1');

        self::assertSame('Acme', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * Confirms built-in simple decoders extend the shared abstract base class.
     *
     * @param string                     $vendor  Expected vendor name in the decoded record.
     * @param MakerNotesDecoderInterface $decoder Decoder under test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('provideSimpleDecoders')]
    public function builtInSimpleDecodersExtendSharedBaseClass(string $vendor, MakerNotesDecoderInterface $decoder): void
    {
        $raw    = "maker-note\0payload";
        $record = $decoder->decode($raw, $vendor, null);

        self::assertInstanceOf(AbstractSimpleDecoder::class, $decoder);
        self::assertSame($vendor, $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    /**
     * @return iterable<string, array{0:string, 1:MakerNotesDecoderInterface}>
     */
    public static function provideSimpleDecoders(): iterable
    {
        yield 'canon' => ['Canon', new CanonDecoder()];
        yield 'nikon' => ['Nikon', new NikonDecoder()];
        yield 'sony' => ['Sony', new SonyDecoder()];
    }
}
