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
final class AppleDecoderFlagMaskTest extends TestCase
{
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
        ksort($result);

        $expected = [
            'hdrAuto'               => true,
            'hdrEnabled'            => true,
            'longExposure'          => true,
            'nightMode'             => true,
            'personInPhoto'         => true,
            'petInPhoto'            => true,
        ];
        ksort($expected);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function extractFlagsDefaultsPhotosAppFeatureBitsToFalse(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'extractFlags');

        $dictionary = [
            'SceneFlags'            => 0b100000,
            'PhotosAppFeatureFlags' => [
                'values' => [9, 15],
            ],
            'ImageProcessingFlags'  => 0,
        ];

        $result = $method->invoke($decoder, $dictionary);

        self::assertSame([
            'personInPhoto' => false,
            'petInPhoto'    => false,
        ], $result);
    }
}
