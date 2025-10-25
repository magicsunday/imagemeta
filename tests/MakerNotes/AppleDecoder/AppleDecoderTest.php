<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\AppleDecoder;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function hex2bin;
use function sha1;
use function str_repeat;
use function strlen;

/**
 * Validates the Apple maker notes decoder implementation.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderTest extends TestCase
{
    /**
     * Ensures the decoder extracts structured Apple maker note data from a representative payload.
     */
    #[Test]
    public function decodeParsesAppleMakerNotes(): void
    {
        $raw     = $this->buildMakerNotesBlob();
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);
        self::assertSame('Apple', $metadata->vendor());
        self::assertSame(strlen($raw), $metadata->length());
        self::assertSame(sha1($raw), $metadata->sha1());

        $apple = $metadata->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('photo-uuid', $apple->contentIdentifier);
        self::assertSame('Tele', $apple->cameraType);
        self::assertEqualsWithDelta(2.5, $apple->hdrHeadroom, 1e-12);
        self::assertSame([1.0, 1.2, 1.3], $apple->hdrGain);
        self::assertEqualsWithDelta(24.5, $apple->snr, 1e-12);
        self::assertEqualsWithDelta(0.62, $apple->focusPosition, 1e-12);
        self::assertSame(2, $apple->livePhotoIndex);
        self::assertSame(5000, $apple->colorTemperature);
        self::assertSame('Warm', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.15, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.05, $apple->semanticStyleTone, 1e-12);
        self::assertSame([0.1, -0.2, 0.3], $apple->accelerationVector);

        self::assertTrue($apple->flags['livePhotoAuto']);
        self::assertArrayHasKey('nightMode', $apple->flags);
        self::assertFalse($apple->flags['nightMode']);
    }

    private function buildMakerNotesBlob(): string
    {
        $hex = '62706c6973743030de0102030405060708090a0b0c0d0e0f13141516171b1c1d1e1f2021225f1012416363656c65726174696f6e566563746f725a43616d657261547970655f1010436f6c6f7254656d70657261747572655f1011436f6e74656e744964656e7469666965725d466f637573506f736974696f6e574864724761696e5b48647248656164726f6f6d5d4c69766550686f746f4175746f5f10134c69766550686f746f566964656f496e646578594e696768744d6f64655a534e5253657474696e675f101353656d616e7469635374796c655072657365745f101153656d616e7469635374796c65546f6e655f101353656d616e7469635374796c655761726d7468a3101112233fb999999999999a23bfc999999999999a233fd33333333333335454656c651113885a70686f746f2d75756964233fe3d70a3d70a3d7a318191a233ff0000000000000233ff3333333333333233ff4cccccccccccd23400400000000000009100208234038800000000000545761726d23bfa999999999999a233fc333333333333300080025003a00450058006c007a0082008e009c00b200bc00c700dd00f10107010b0114011d0126012b012e013901420146014f01580161016a016b016d016e0177017c0185000000000000020100000000000000230000000000000000000000000000018e';

        return (string) hex2bin($hex);
    }

    #[Test]
    public function decodeIgnoresUnsupportedPropertyListFormat(): void
    {
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode('Apple iOS' . str_repeat("\x00", 32), 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);
        self::assertNull($metadata->apple());
    }

    #[Test]
    public function flagMasksMirrorScalarInputs(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $scalarNotes = $method->invoke($decoder, [
            'ContentIdentifier'    => 'scalar',
            'LivePhotoAuto'        => true,
            'LivePhotoEnabled'     => true,
            'LivePhotoActive'      => true,
            'LivePhotoLongExposure'=> true,
            'LivePhoto'            => 1,
            'HdrAuto'              => 1,
            'HdrEnabled'           => '1',
            'NightMode'            => true,
            'LongExposure'         => true,
        ]);

        $maskNotes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'mask',
            'SceneFlags'            => (1 << 0) | (1 << 1),
            'ImageProcessingFlags'  => (1 << 0) | (1 << 1),
            'PhotosAppFeatureFlags' => (1 << 0) | (1 << 1) | (1 << 2) | (1 << 3) | (1 << 4),
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $scalarNotes);
        self::assertInstanceOf(AppleMakerNotes::class, $maskNotes);

        $scalarFlags = $scalarNotes->flags;
        $maskFlags   = $maskNotes->flags;

        ksort($scalarFlags);
        ksort($maskFlags);

        self::assertSame($scalarFlags, $maskFlags);
    }

    #[Test]
    public function explicitFlagValuesOverrideMasks(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $notes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'override',
            'LivePhotoLongExposure' => false,
            'NightMode'             => false,
            'SceneFlags'            => 1 << 0,
            'PhotosAppFeatureFlags' => 1 << 4,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame(
            [
                'livePhotoLongExposure' => false,
                'nightMode'             => false,
            ],
            $notes->flags,
        );
    }
}
