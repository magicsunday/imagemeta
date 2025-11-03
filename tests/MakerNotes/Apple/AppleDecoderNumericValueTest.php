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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderNumericValueTest extends TestCase
{
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
