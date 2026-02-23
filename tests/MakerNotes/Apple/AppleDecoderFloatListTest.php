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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AppleDecoder float list normalization for maker note values.
 * It verifies scalar values are wrapped into lists and numeric strings become floats.
 * The suite covers mixed numeric arrays to ensure consistent float output.
 * This keeps numeric maker note lists predictable for consumers.
 *
 * @internal
 */
#[CoversClass(AppleDictionaryValueExtractor::class)]
final class AppleDecoderFloatListTest extends TestCase
{
    /**
     * Calls floatList with a scalar value for the requested key.
     * Ensures the scalar is wrapped into a single-element float array.
     *
     * @return void
     */
    #[Test]
    public function floatListReturnsScalarValuesAsLists(): void
    {
        $extractor = new AppleDictionaryValueExtractor();

        $result = $extractor->floatList(['HdrGain' => 1.25], 'HdrGain');

        self::assertSame([1.25], $result);
    }

    /**
     * Provides a values list containing integers, numeric strings, and floats.
     * Confirms floatList normalizes the payload into a list of floats.
     *
     * @return void
     */
    #[Test]
    public function floatListNormalisesArrayPayloads(): void
    {
        $extractor = new AppleDictionaryValueExtractor();

        $dictionary = [
            'HdrGain' => [
                'values' => [1, '2.5', 3.75],
            ],
        ];

        $result = $extractor->floatList($dictionary, 'HdrGain');

        self::assertSame([1.0, 2.5, 3.75], $result);
    }
}
