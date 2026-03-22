<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleJpegIfdParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use MagicSunday\ImageMeta\MakerNotes\RegistryFactory;
use MagicSunday\ImageMeta\MakerNotes\SamsungDecoder;
use MagicSunday\ImageMeta\MakerNotes\SimpleDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function array_slice;
use function file;
use function implode;

/**
 * Exercises the maker notes Registry for decoder lookup by make strings.
 * It verifies prefix and case-insensitive matching for registered decoders.
 * The suite checks fallback behavior when no decoder matches.
 * This ensures vendor-specific maker note decoders are resolved reliably.
 */
#[CoversClass(Registry::class)]
#[UsesClass(RegistryFactory::class)]
#[UsesClass(AppleDecoder::class)]
#[UsesClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleJpegIfdParser::class)]
#[UsesClass(AppleMakerNotesBuilder::class)]
#[UsesClass(SimpleDecoder::class)]
final class RegistryTest extends TestCase
{
    private function readMethodBody(string $class, string $methodName): string
    {
        $method   = new ReflectionMethod($class, $methodName);
        $fileName = $method->getFileName();
        self::assertIsString($fileName);

        $sourceLines = file($fileName);
        self::assertIsArray($sourceLines);
        $startLine = $method->getStartLine();
        $endLine   = $method->getEndLine();
        self::assertIsInt($startLine);
        self::assertIsInt($endLine);

        return implode(
            '',
            array_slice(
                $sourceLines,
                $startLine - 1,
                $endLine - $startLine + 1,
            ),
        );
    }

    /**
     * Provides make strings that should resolve to the registered decoder.
     * This checks the behavior for the specific inputs used in the test.
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
     * Registers an Apple decoder and tests several make string variants.
     * Confirms the registry resolves the same decoder for matching prefixes and casing.
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
             * This checks the behavior for the specific inputs used in the test.
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
     * Registers a Canon decoder but searches with a Nikon make string.
     * Ensures the registry returns null when no registered prefix matches.
     */
    #[Test]
    public function returnsNullWhenNoPrefixMatches(): void
    {
        $registry = new Registry();
        $registry->register('Canon', new class implements MakerNotesDecoderInterface {
            /**
             * Decodes the maker notes payload for the registered Canon decoder in this test.
             * This checks the behavior for the specific inputs used in the test.
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
     * Builds the default registry via RegistryFactory::createDefault().
     * Verifies that built-in decoders are registered for common camera makers.
     */
    #[Test]
    public function factoryRegistersBuiltInDecoders(): void
    {
        $registry = RegistryFactory::createDefault();

        self::assertInstanceOf(SimpleDecoder::class, $registry->find('Canon Inc.'));
        self::assertInstanceOf(SimpleDecoder::class, $registry->find('Nikon Corporation'));
        self::assertInstanceOf(SamsungDecoder::class, $registry->find('SAMSUNG'));
        self::assertInstanceOf(SimpleDecoder::class, $registry->find('Sony Corporation'));
    }

    #[Test]
    public function factoryAvoidsRedundantUppercaseSamsungRegistration(): void
    {
        $body = $this->readMethodBody(RegistryFactory::class, 'createDefault');

        self::assertStringNotContainsString("\$registry->register('SAMSUNG', \$samsungDecoder);", $body);
    }
}
