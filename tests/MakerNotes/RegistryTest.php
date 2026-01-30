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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\NikonDecoder;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MakerNotes\SamsungDecoder;
use MagicSunday\ImageMeta\MakerNotes\SonyDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the maker notes registry behaviour.
 * */
#[CoversClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
final class RegistryTest extends TestCase
{
    /**
     * Provides make strings that should resolve to the registered decoder.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function matchingMakeProvider(): iterable
    {
        yield 'exact match' => ['Apple'];
        yield 'mixed casing' => ['APPLE'];
        yield 'prefixed model' => ['apple iPhone 15 Pro'];
    }

    /**
     * Verifies that $registry->find($make) equals $decoder.
     *
     * @param string $make The make string used during lookup.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('matchingMakeProvider')]
    public function findsDecoderForRegisteredPrefix(string $make): void
    {
        $decoder = new class implements MakerNotesDecoderInterface {
            /**
             * Decodes the maker notes payload for the registered Apple decoder in this test.
             *
             * @param string      $raw   The raw maker notes data to decode.
             * @param string      $make  The make string associated with the image metadata.
             * @param string|null $model The optional model string associated with the image metadata.
             *
             * @return MakerNotesRecord The decoded maker notes metadata instance.
             */
            public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
            {
                return new MakerNotesRecord('Test', 0, '0000000000000000000000000000000000000000');
            }
        };

        $registry = new Registry();
        $registry->register('Apple', $decoder);

        self::assertSame($decoder, $registry->find($make));
    }

    /**
     * Verifies that $registry->find('Nikon Corporation') is null.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenNoPrefixMatches(): void
    {
        $registry = new Registry();
        $registry->register('Canon', new class implements MakerNotesDecoderInterface {
            /**
             * Decodes the maker notes payload for the registered Canon decoder in this test.
             *
             * @param string      $raw   The raw maker notes data to decode.
             * @param string      $make  The make string associated with the image metadata.
             * @param string|null $model The optional model string associated with the image metadata.
             *
             * @return MakerNotesRecord The decoded maker notes metadata instance.
             */
            public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
            {
                return new MakerNotesRecord('Canon', 0, '0000000000000000000000000000000000000000');
            }
        });

        self::assertNull($registry->find('Nikon Corporation'));
    }

    /**
     * Verifies that $registry->find('Canon Inc.') is instance of CanonDecoder::class.
     *
     * @return void
     */
    #[Test]
    public function factoryRegistersBuiltInDecoders(): void
    {
        $registry = RegistryFactory::createDefault();

        self::assertInstanceOf(CanonDecoder::class, $registry->find('Canon Inc.'));
        self::assertInstanceOf(NikonDecoder::class, $registry->find('Nikon Corporation'));
        self::assertInstanceOf(SamsungDecoder::class, $registry->find('SAMSUNG'));
        self::assertInstanceOf(SonyDecoder::class, $registry->find('Sony Corporation'));
    }
}
