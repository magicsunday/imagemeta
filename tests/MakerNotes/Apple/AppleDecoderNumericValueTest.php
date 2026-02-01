<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises AppleDecoder numeric normalization helpers for maker note fields.
 * It verifies rational pairs and whitespace-separated numeric values are parsed correctly.
 * The suite checks integer and float coercion paths for mixed input types.
 * This ensures numeric maker note values are stable and predictable.
 *
 * @internal
 */
#[CoversClass(AppleDecoder::class)]
final class AppleDecoderNumericValueTest extends TestCase
{
    /**
     * Provides a whitespace-separated numerator/denominator value for AFPerformance.
     * Confirms rationalFloatValue converts the pair into a floating-point ratio.
     *
     * @return void
     */
    #[Test]
    public function rationalFloatValueNormalisesWhitespaceSeparatedPairs(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'rationalFloatValue');

        $dictionary = [
            'AFPerformance' => '44 1610612736',
        ];

        $result = $method->invoke($decoder, $dictionary, 'AFPerformance');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(44 / 1610612736, $result, 1e-12);
    }

    /**
     * Calls numericScalarValue with a whitespace-separated numeric pair.
     * Ensures the helper parses the pair into a float ratio.
     *
     * @return void
     */
    #[Test]
    public function numericScalarValueParsesWhitespaceSeparatedPairs(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'numericScalarValue');

        $result = $method->invoke($decoder, '44 1610612736');

        self::assertNotNull($result);
        self::assertEqualsWithDelta(44 / 1610612736, $result, 1e-12);
    }
}
