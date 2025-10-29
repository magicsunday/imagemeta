<?php

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

final class SemanticStyleTest extends TestCase
{
    public function testFromQuickTimeParsesModernStructure(): void
    {
        $meta = $this->createQuickTimeMeta([
            'SemanticStyle' => [
                '_0' => 'Vivid',
                '_2' => 0.15,
                '_3' => ['value' => '0.25'],
            ],
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
     * @param array<string, mixed> $keys
     */
    private function createQuickTimeMeta(array $keys): QuickTimeMeta
    {
        $reflection = new ReflectionClass(QuickTimeMeta::class);
        /** @var QuickTimeMeta $meta */
        $meta = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('keys');
        $property->setAccessible(true);
        $property->setValue($meta, $keys);

        return $meta;
    }
}
