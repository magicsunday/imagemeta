<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SemanticStyleTest extends TestCase
{
    public function testFromQuickTimeParsesModernStructure(): void
    {
        $meta = $this->quickTimeMeta([
            '_0' => 'Vivid',
            '_2' => 0.15,
            '_3' => ['value' => '0.25'],
        ]);

        self::assertSame(['Vivid', 0.15, 0.25], SemanticStyle::fromQuickTime($meta));
    }

    public function testFromDictionaryParsesLegacyStructure(): void
    {
        $dictionary = [
            'SemanticStyle' => [
                'values' => [
                    0 => 'Warm',
                    1 => ['value' => 0.5],
                    2 => ['Value' => '0.75'],
                ],
            ],
        ];

        self::assertSame(['Warm', 0.5, 0.75], SemanticStyle::fromDictionary($dictionary));
    }

    public function testFromValueReturnsNullWhenNoComponents(): void
    {
        self::assertNull(SemanticStyle::fromValue(['values' => []]));
    }

    /**
     * Creates a QuickTime metadata container populated with the supplied semantic style payload.
     *
     * @param array<int|string, mixed> $semanticStyle
     */
    private function quickTimeMeta(array $semanticStyle): QuickTimeMeta
    {
        $reflector = new ReflectionClass(QuickTimeMeta::class);

        /** @var QuickTimeMeta $meta */
        $meta = $reflector->newInstanceWithoutConstructor();
        $property = $reflector->getProperty('keys');
        $property->setAccessible(true);
        $property->setValue($meta, ['SemanticStyle' => $semanticStyle]);

        return $meta;
    }
}
