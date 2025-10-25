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
use MagicSunday\ImageMeta\MakerNotes\Apple\RunTime;
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
     * Ensures keyed archive payloads map integer camera type codes to descriptive labels.
     */
    #[Test]
    public function decodeMapsCameraTypeCodeFromKeyedArchive(): void
    {
        $hex = ''
            . '62706c6973743030d401020304050622265924617263686976657258246f626a'
            . '656374735424746f70582476657273696f6e5f100f4e534b6579656441726368'
            . '69766572a70708191f20211855246e756c6cd3090a0b0c0f145624636c617373'
            . '574e532e6b6579735a4e532e6f626a65637473d10d0e564346245549441002a2'
            . '1012d10d111003d10d131004a21517d10d161005d10d181006d21a1b1c1d5824'
            . '636c61737365735a24636c6173736e616d65a21d1e5c4e5344696374696f6e61'
            . '7279584e534f626a6563745f1011436f6e74656e744964656e7469666965725a'
            . '43616d657261547970655f101361726368697665642d70686f746f2d75756964'
            . 'd1232454726f6f74d10d25100112000186a000080011001b0024002900320044'
            . '004c005200590060006800730076007d007f008200850087008a008c008f0092'
            . '009400970099009e00a700b200b500c200cb00df00ea010001030108010b010d'
            . '0000000000000201000000000000002700000000000000000000000000000112';
        $raw = (string) hex2bin($hex);
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);

        $apple = $metadata->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('archived-photo-uuid', $apple->contentIdentifier);
        self::assertSame('Front', $apple->cameraType);
    }

    /**
     * Ensures the decoder resolves keyed archive maker note data into structured metadata.
     */
    #[Test]
    public function decodeUnarchivesKeyedArchivePayload(): void
    {
        $hex = ''
            . '62706c6973743030d40102030405066e725924617263686976657258246f626a656374735424746f70582476657273696f6e5f100f4e534b65796564'
            . '4172636869766572af101f07080f101112131415161718191a1b1c1d1e1f232425262728292d2e2f303155246e756c6cd2090a0b0c5824636c617373'
            . '65735a24636c6173736e616d65a30c0d0e5f10134e534d757461626c6544696374696f6e6172795c4e5344696374696f6e617279584e534f626a6563'
            . '745f1011436f6e74656e744964656e7469666965725b48647248656164726f6f6d574864724761696e5a534e5253657474696e675d466f637573506f'
            . '736974696f6e5f10134c69766550686f746f566964656f496e6465785f101353656d616e7469635374796c655072657365745f101353656d616e7469'
            . '635374796c655761726d74685f101153656d616e7469635374796c65546f6e655f1012416363656c65726174696f6e566563746f725f101550686f74'
            . '6f7341707046656174757265466c6167735a5363656e65466c6167735f1014496d61676550726f63657373696e67466c6167735d4c69766550686f74'
            . '6f4175746f5f101361726368697665642d70686f746f2d7575696423400a000000000000a3202122233ff0000000000000233ff8000000000000233f'
            . 'fc000000000000234037800000000000233fe28f5c28f5c28f10055c4472616d617469635761726d233fd333333333333323bfc3333333333333a32a'
            . '2b2c233fbeb851eb851eb823bfd5c28f5c28f5c3233fe1eb851eb851ec10151002100309d33233343538525624636c617373574e532e6b6579735a4e'
            . '532e6f626a65637473d13637564346245549441001ae393a3b3d3e40424446484a4c4e50d1362ed1362fd1363c1004d13625d1363f1006d136411007'
            . 'd136431008d136451009d13647100ad13649100bd1364b100cd1364d100dd1364f100ed13651100fae535557595b5d5e60626466686a6cd136541010'
            . 'd136561011d136581012d1365a1013d1365c1014d1362dd1365f1016d136611017d136631018d136651019d13667101ad13669101bd1366b101cd136'
            . '6d101dd16f7054726f6f74d13671101e12000186a000080011001b00240029003200440066006c0071007a00850089009f00ac00b500c900d500dd00'
            . 'e800f6010c01220138014c016101790184019b01a901bf01c801cc01d501de01e701f001f901fb02080211021a021e022702300239023b023d023f02'
            . '400247024e025602610264026b026d027c027f028202850287028a028d028f0292029402970299029c029e02a102a302a602a802ab02ad02b002b202'
            . 'b502b702ba02bc02cb02ce02d002d302d502d802da02dd02df02e202e402e702ea02ec02ef02f102f402f602f902fb02fe0300030303050308030a03'
            . '0d030f03120317031a031c0000000000000201000000000000007300000000000000000000000000000321'
        ;
        $raw = (string) hex2bin($hex);
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);

        $apple = $metadata->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('archived-photo-uuid', $apple->contentIdentifier);
        self::assertEqualsWithDelta(3.25, $apple->hdrHeadroom, 1e-12);
        self::assertSame([1.0, 1.5, 1.75], $apple->hdrGain);
        self::assertEqualsWithDelta(23.5, $apple->snr, 1e-12);
        self::assertEqualsWithDelta(0.58, $apple->focusPosition, 1e-12);
        self::assertSame(5, $apple->livePhotoIndex);
        self::assertSame('DramaticWarm', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.3, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.15, $apple->semanticStyleTone, 1e-12);
        self::assertSame([0.12, -0.34, 0.56], $apple->accelerationVector);

        self::assertArrayHasKey('livePhoto', $apple->flags);
        self::assertArrayHasKey('livePhotoAuto', $apple->flags);
        self::assertArrayHasKey('livePhotoEnabled', $apple->flags);
        self::assertArrayHasKey('livePhotoLongExposure', $apple->flags);
        self::assertArrayHasKey('hdrAuto', $apple->flags);
        self::assertArrayHasKey('hdrEnabled', $apple->flags);
        self::assertArrayHasKey('longExposure', $apple->flags);

        self::assertTrue($apple->flags['livePhoto']);
        self::assertTrue($apple->flags['livePhotoAuto']);
        self::assertTrue($apple->flags['livePhotoEnabled']);
        self::assertTrue($apple->flags['livePhotoLongExposure']);
        self::assertTrue($apple->flags['hdrAuto']);
        self::assertTrue($apple->flags['hdrEnabled']);
        self::assertTrue($apple->flags['longExposure']);
    }

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

    #[Test]
    public function parseAppleDataReturnsNotesForBinaryPlist(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'parseAppleData');
        $method->setAccessible(true);

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, $this->buildMakerNotesBlob());

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
    }

    #[Test]
    public function parseAppleDataParsesRawDictionaryPayload(): void
    {
        $raw = <<<'RAW'
        {
            ContentIdentifier = "raw-dictionary";
            CameraType = "Back Wide Angle";
            HdrHeadroom = 2.75;
            HdrGain = (1.0, 1.15, 1.3);
            SNRSetting = 21.5;
            FocusPosition = 0.5;
            LivePhotoVideoIndex = 3;
            ColorTemperature = 5400;
            SemanticStylePreset = Warm;
            SemanticStyleWarmth = 0.2;
            SemanticStyleTone = -0.1;
            AccelerationVector = (0.1 -0.2 0.3);
            RunTime = { epoch = 1; timescale = 30; value = 90; flags = 2; };
            LivePhotoEnabled = YES;
        }
        RAW;

        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'parseAppleData');
        $method->setAccessible(true);

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, $raw);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame('raw-dictionary', $notes->contentIdentifier);
        self::assertSame('Back Wide Angle', $notes->cameraType);
        self::assertEqualsWithDelta(2.75, $notes->hdrHeadroom, 1e-12);
        self::assertSame([1.0, 1.15, 1.3], $notes->hdrGain);
        self::assertEqualsWithDelta(21.5, $notes->snr, 1e-12);
        self::assertEqualsWithDelta(0.5, $notes->focusPosition, 1e-12);
        self::assertSame(3, $notes->livePhotoIndex);
        self::assertSame(5400, $notes->colorTemperature);
        self::assertSame('Warm', $notes->semanticStylePreset);
        self::assertEqualsWithDelta(0.2, $notes->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.1, $notes->semanticStyleTone, 1e-12);
        self::assertSame([0.1, -0.2, 0.3], $notes->accelerationVector);
        self::assertInstanceOf(RunTime::class, $notes->runTime);
        self::assertSame(1, $notes->runTime?->epoch);
        self::assertSame(30, $notes->runTime?->timescale);
        self::assertSame(90, $notes->runTime?->value);
        self::assertSame(2, $notes->runTime?->flags);
        self::assertArrayHasKey('livePhotoEnabled', $notes->flags);
        self::assertTrue($notes->flags['livePhotoEnabled']);
    }

    #[Test]
    public function decodeMapsSemanticStyleFromCompactArray(): void
    {
        $raw     = (string) hex2bin('62706c6973743030d2010203045f1011436f6e74656e744964656e7469666965725d53656d616e7469635374796c655d636f6d706163742d7374796c65d405060708090a0b0c525f30525f31525f32525f33555669766964233fd000000000000023bfb999999999999a1001080d212f3d46494c4f5258616a0000000000000101000000000000000d0000000000000000000000000000006c');
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        self::assertInstanceOf(MakerNotesMetadata::class, $metadata);

        $apple = $metadata->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('compact-style', $apple->contentIdentifier);
        self::assertSame('Vivid', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.25, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.1, $apple->semanticStyleTone, 1e-12);
    }

    #[Test]
    public function buildAppleMakerNotesHandlesCameraTypeCodes(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $mapped = $method->invoke($decoder, [
            'ContentIdentifier' => 'mapped-camera',
            'CameraType'        => 0,
        ]);

        $unknown = $method->invoke($decoder, [
            'ContentIdentifier' => 'unknown-camera',
            'CameraType'        => 42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $mapped);
        self::assertInstanceOf(AppleMakerNotes::class, $unknown);
        self::assertSame('Back Wide Angle', $mapped->cameraType);
        self::assertSame(42, $unknown->cameraType);
    }

    #[Test]
    public function buildAppleMakerNotesFallsBackToSemanticStyleDictionary(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'dictionary-style',
            'SemanticStyle'     => [
                'values' => [
                    '_0' => 'DictionaryPreset',
                    '_2' => ['value' => 0.45],
                    '_3' => -0.25,
                ],
            ],
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame('DictionaryPreset', $notes->semanticStylePreset);
        self::assertEqualsWithDelta(0.45, $notes->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.25, $notes->semanticStyleTone, 1e-12);
    }

    #[Test]
    public function buildAppleMakerNotesParsesRunTime(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $dictionary = [
            'RunTime'             => [
                'epoch'     => '2',
                'timescale' => 600,
                'value'     => 1500,
                'flags'     => 5,
            ],
            'LivePhotoVideoIndex' => 1200,
        ];

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, $dictionary);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame(1200, $notes->livePhotoIndex);
        self::assertEqualsWithDelta(2.0, $notes->livePhotoTime, 1e-12);
        self::assertInstanceOf(RunTime::class, $notes->runTime);
        self::assertSame(2, $notes->runTime?->epoch);
        self::assertSame(600, $notes->runTime?->timescale);
        self::assertSame(1500, $notes->runTime?->value);
        self::assertSame(5, $notes->runTime?->flags);
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
            'ContentIdentifier'     => 'scalar',
            'LivePhotoAuto'         => true,
            'LivePhotoEnabled'      => true,
            'LivePhotoActive'       => true,
            'LivePhotoLongExposure' => true,
            'LivePhoto'             => 1,
            'HdrAuto'               => 1,
            'HdrEnabled'            => '1',
            'NightMode'             => true,
            'LongExposure'          => true,
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

    #[Test]
    public function flagMasksAcceptBitPositionLists(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $notes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'positions',
            'SceneFlags'            => [0, 1],
            'ImageProcessingFlags'  => ['values' => [0, 1]],
            'PhotosAppFeatureFlags' => [0, 1, ['values' => [2, 3]], 4],
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);

        $flags = $notes->flags;
        ksort($flags);

        self::assertSame(
            [
                'hdrAuto'               => true,
                'hdrEnabled'            => true,
                'livePhoto'             => true,
                'livePhotoActive'       => true,
                'livePhotoAuto'         => true,
                'livePhotoEnabled'      => true,
                'livePhotoLongExposure' => true,
                'longExposure'          => true,
                'nightMode'             => true,
            ],
            $flags,
        );
    }
}
