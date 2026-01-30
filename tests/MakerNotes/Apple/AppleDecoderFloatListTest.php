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

#[CoversClass(AppleDecoder::class)]
final class AppleDecoderFloatListTest extends TestCase
{
    /**
     * Verifies that $result equals [1.25].
     *
     * @return void
     */
    #[Test]
    public function floatListReturnsScalarValuesAsLists(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'floatList');

        $result = $method->invoke($decoder, ['HdrGain' => 1.25], 'HdrGain');

        self::assertSame([1.25], $result);
    }

    /**
     * Verifies that $result equals [1.0, 2.5, 3.75].
     *
     * @return void
     */
    #[Test]
    public function floatListNormalisesArrayPayloads(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'floatList');

        $dictionary = [
            'HdrGain' => [
                'values' => [1, '2.5', 3.75],
            ],
        ];

        $result = $method->invoke($decoder, $dictionary, 'HdrGain');

        self::assertSame([1.0, 2.5, 3.75], $result);
    }
}
