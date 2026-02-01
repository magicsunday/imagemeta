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
 * Exercises AppleDecoder flag extraction from bitmasks and flag lists.
 * It verifies that numeric and hex-encoded masks map to normalized boolean flags.
 * The suite checks combined sources (SceneFlags, ImageProcessingFlags, PhotosAppFeatureFlags).
 * This ensures maker note flags are interpreted consistently for downstream metadata.
 *
 * @internal
 */
#[CoversClass(AppleDecoder::class)]
final class AppleDecoderFlagMaskTest extends TestCase
{
    /**
     * Invokes extractFlags with bitmask inputs and a values list that map to known flags.
     * Confirms the resulting normalized flags are set to true for each mapped feature.
     *
     * @return void
     */
    #[Test]
    public function extractFlagsDerivesNormalizedFlagsFromBitMasks(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'extractFlags');

        $dictionary = [
            'SceneFlags'            => 0b11,
            'ImageProcessingFlags'  => '0x3',
            'PhotosAppFeatureFlags' => [
                'values' => [0, 1],
            ],
        ];

        $result = $method->invoke($decoder, $dictionary);
        self::assertIsArray($result);
        ksort($result);

        $expected = [
            'hdrAuto'       => true,
            'hdrEnabled'    => true,
            'longExposure'  => true,
            'nightMode'     => true,
            'personInPhoto' => true,
            'petInPhoto'    => true,
        ];
        ksort($expected);

        self::assertSame($expected, $result);
    }

    /**
     * Supplies bitmask inputs that do not enable any known flags.
     * Ensures the normalized flags default to false when no bits are matched.
     *
     * @return void
     */
    #[Test]
    public function extractFlagsAssignsFalseDefaultsWhenNoMappedBitsEnabled(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'extractFlags');

        $dictionary = [
            'SceneFlags'            => 0b100000,
            'PhotosAppFeatureFlags' => [
                'values' => [9, 15],
            ],
            'ImageProcessingFlags' => 0,
        ];

        $result = $method->invoke($decoder, $dictionary);
        self::assertIsArray($result);
        ksort($result);

        $expected = [
            'hdrAuto'       => false,
            'hdrEnabled'    => false,
            'longExposure'  => false,
            'nightMode'     => false,
            'personInPhoto' => false,
            'petInPhoto'    => false,
        ];
        ksort($expected);

        self::assertSame($expected, $result);
    }
}
