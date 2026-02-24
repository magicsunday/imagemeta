<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleRationalNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AppleDecoder numeric normalization helpers for maker note fields.
 * It verifies rational pairs and whitespace-separated numeric values are parsed correctly.
 * The suite checks integer and float coercion paths for mixed input types.
 * This ensures numeric maker note values are stable and predictable.
 *
 * @internal
 */
#[CoversClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleRationalNormaliser::class)]
final class AppleDecoderNumericValueTest extends TestCase
{
    /**
     * Provides a whitespace-separated numerator/denominator value for AFPerformance.
     * Confirms rationalFloatValue converts the pair into a floating-point ratio.
     */
    #[Test]
    public function rationalFloatValueNormalisesWhitespaceSeparatedPairs(): void
    {
        $extractor = new AppleDictionaryValueExtractor();

        $dictionary = [
            'AFPerformance' => '44 1610612736',
        ];

        $result = $extractor->rationalFloatValue($dictionary, 'AFPerformance');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(44 / 1610612736, $result, 1e-12);
    }

    /**
     * Calls numericScalarValue with a whitespace-separated numeric pair.
     * Ensures the helper parses the pair into a float ratio.
     */
    #[Test]
    public function numericScalarValueParsesWhitespaceSeparatedPairs(): void
    {
        $normaliser = new AppleRationalNormaliser();

        $result = $normaliser->numericScalarValue('44 1610612736');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(44 / 1610612736, $result, 1e-12);
    }
}
