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
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests the maker notes registry behaviour.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\Registry
 */
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
     * Ensures the registered decoder can be found for matching prefixes regardless of case.
     *
     * @param string $make The make string used during lookup.
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
             * @return MakerNotesMetadata The decoded maker notes metadata instance.
             */
            public function decode(string $raw, string $make, ?string $model): MakerNotesMetadata
            {
                return new MakerNotesMetadata('Test', 0, '0000000000000000000000000000000000000000');
            }
        };

        $registry = new Registry();
        $registry->register('Apple', $decoder);

        self::assertSame($decoder, $registry->find($make));
    }

    /**
     * Ensures null is returned when no registered prefix matches the provided make string.
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
             * @return MakerNotesMetadata The decoded maker notes metadata instance.
             */
            public function decode(string $raw, string $make, ?string $model): MakerNotesMetadata
            {
                return new MakerNotesMetadata('Canon', 0, '0000000000000000000000000000000000000000');
            }
        });

        self::assertNull($registry->find('Nikon Corporation'));
    }
}
