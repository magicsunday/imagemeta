<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Exercises SemanticStyle parsing from QuickTime and legacy dictionary payloads.
 * It verifies preset, warmth, and tone values are normalized across formats.
 * The suite checks mixed numeric/string inputs are coerced consistently.
 * This keeps portrait semantic style metadata stable for maker note consumers.
 *
 * @phpstan-import-type SemanticStyleDictionary from SemanticStyle
 */
#[CoversClass(SemanticStyle::class)]
final class SemanticStyleTest extends TestCase
{
    /**
     * Uses the modern QuickTime SemanticStyle structure with _0/_2/_3 keys.
     * Ensures fromQuickTime returns the normalized preset, warmth, and tone values.
     */
    #[Test]
    public function fromQuickTimeParsesModernStructure(): void
    {
        $meta = $this->quickTimeMeta([
            '_0' => 'Vivid',
            '_2' => 0.15,
            '_3' => ['value' => '0.25'],
        ]);

        self::assertSame(['Vivid', 0.15, 0.25], SemanticStyle::fromQuickTime($meta));
    }

    /**
     * Supplies a legacy dictionary payload containing a SemanticStyle values array.
     * Verifies fromDictionary extracts the expected preset, warmth, and tone entries.
     */
    #[Test]
    public function fromDictionaryParsesLegacyStructure(): void
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

    /**
     * Uses a deeply nested payload with mixed string and numeric values.
     * Ensures fromValue normalizes nesting, trims strings, and returns the ordered tuple.
     */
    #[Test]
    public function fromValueNormalizesDeeplyNestedStructure(): void
    {
        $payload = $this->nestedSemanticStylePayload();

        self::assertSame(['Cinematic', 0.45, 0.67], SemanticStyle::fromValue($payload));
    }

    /**
     * Supplies a payload with an empty values list.
     * Confirms fromValue returns null when no semantic components are present.
     */
    #[Test]
    public function fromValueReturnsNullWhenNoComponents(): void
    {
        self::assertNull(SemanticStyle::fromValue(['values' => []]));
    }

    /**
     * Builds a deeply nested semantic style payload used for normalisation tests.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @return array{
     *     values: array{
     *         Values: array{
     *             0: array{value: array{Value: array{value: array{Value: array{value: string}}}}},
     *             1: array{value: list<array{Value: array{value: array{Value: array{value: float}}}}>},
     *             2: array{value: list<array{value: list<array{Value: string}>}>}
     *         }
     *     }
     * }
     */
    private function nestedSemanticStylePayload(): array
    {
        return [
            'values' => [
                'Values' => [
                    0 => [
                        'value' => [
                            'Value' => [
                                'value' => [
                                    'Value' => [
                                        'value' => '  Cinematic  ',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    1 => [
                        'value' => [
                            [
                                'Value' => [
                                    'value' => [
                                        'Value' => [
                                            'value' => 0.45,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    2 => [
                        'value' => [
                            [
                                'value' => [
                                    [
                                        'Value' => '0.67',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Creates a QuickTime metadata container populated with the supplied semantic style payload.
     * This checks the behavior for the specific inputs used in the test.
     *
     * @param SemanticStyleDictionary $semanticStyle
     *
     * @phpstan-param SemanticStyleDictionary $semanticStyle
     */
    private function quickTimeMeta(array $semanticStyle): QuickTimeMeta
    {
        $reflector = new ReflectionClass(QuickTimeMeta::class);

        /** @var QuickTimeMeta $meta */
        $meta      = $reflector->newInstanceWithoutConstructor();
        $property  = $reflector->getProperty('keys');
        $property->setValue($meta, ['SemanticStyle' => $semanticStyle]);

        return $meta;
    }
}
