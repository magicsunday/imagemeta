<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function hex2bin;
use function sha1;
use function str_repeat;
use function str_replace;
use function strlen;

/**
 * Validates the Apple maker notes decoder implementation.
 */
#[CoversClass(AppleDecoder::class)]
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
        $raw     = (string) hex2bin($hex);
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
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
        $raw     = (string) hex2bin($hex);
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
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

        self::assertArrayHasKey('hdrAuto', $apple->flags);
        self::assertArrayHasKey('hdrEnabled', $apple->flags);
        self::assertArrayHasKey('longExposure', $apple->flags);
        self::assertArrayHasKey('personInPhoto', $apple->flags);
        self::assertArrayHasKey('petInPhoto', $apple->flags);

        self::assertTrue($apple->flags['hdrAuto']);
        self::assertTrue($apple->flags['hdrEnabled']);
        self::assertTrue($apple->flags['longExposure']);
        self::assertTrue($apple->flags['personInPhoto']);
        self::assertFalse($apple->flags['petInPhoto']);
    }

    #[Test]
    public function decodeParsesAppleMakerNotes(): void
    {
        $raw     = $this->buildMakerNotesBlob();
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');
        self::assertSame('Apple', $metadata->vendor);
        self::assertSame(strlen($raw), $metadata->length);
        self::assertSame(sha1($raw), $metadata->sha1);

        $apple = $metadata->apple;
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
    public function decodeAcceptsPaddedDictionaryPayload(): void
    {
        $raw = '{ ContentIdentifier = "padded"; LivePhotoAuto = 1; }' . str_repeat("\0", 8);

        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('padded', $apple->contentIdentifier);
        self::assertTrue($apple->flags['livePhotoAuto']);
    }

    #[Test]
    public function decodeRecordsDisabledFlagsFromZeroBitMasks(): void
    {
        $raw = '{ ContentIdentifier = "flags-zero"; SceneFlags = 0; ImageProcessingFlags = 0; PhotosAppFeatureFlags = 0; }';

        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('flags-zero', $apple->contentIdentifier);

        self::assertArrayHasKey('nightMode', $apple->flags);
        self::assertArrayHasKey('longExposure', $apple->flags);
        self::assertArrayHasKey('hdrEnabled', $apple->flags);
        self::assertArrayHasKey('hdrAuto', $apple->flags);
        self::assertArrayHasKey('personInPhoto', $apple->flags);
        self::assertArrayHasKey('petInPhoto', $apple->flags);

        self::assertFalse($apple->flags['nightMode']);
        self::assertFalse($apple->flags['longExposure']);
        self::assertFalse($apple->flags['hdrEnabled']);
        self::assertFalse($apple->flags['hdrAuto']);
        self::assertFalse($apple->flags['personInPhoto']);
        self::assertFalse($apple->flags['petInPhoto']);
    }

    #[Test]
    public function decodeResolvesLivePhotoMovieIndex(): void
    {
        $raw     = $this->buildMakerNotesBlobWithMovieIndex();
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame(2, $apple->livePhotoIndex);
    }

    #[Test]
    public function decodeMapsSemanticStyleFromCompactArray(): void
    {
        $raw     = (string) hex2bin('62706c6973743030d2010203045f1011436f6e74656e744964656e7469666965725d53656d616e7469635374796c655d636f6d706163742d7374796c65d405060708090a0b0c525f30525f31525f32525f33555669766964233fd000000000000023bfb999999999999a1001080d212f3d46494c4f5258616a0000000000000101000000000000000d0000000000000000000000000000006c');
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');

        $apple = $metadata->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('compact-style', $apple->contentIdentifier);
        self::assertSame('Vivid', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.25, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.1, $apple->semanticStyleTone, 1e-12);
    }

    #[Test]
    public function buildAppleMakerNotesExtractsAdditionalFields(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier'  => 'extended',
            'MakerNoteVersion'   => '2.1',
            'HDRImageType'       => 1,
            'BurstUUID'          => 'burst-uuid',
            'FocusDistanceRange' => [0.45, 1.5],
            'OISMode'            => 2,
            'ImageCaptureType'   => 5,
            'ImageUniqueID'      => 'unique-id',
            'PhotoIdentifier'    => 'photo-id',
            'AFMeasuredDepth'    => 0.75,
            'AFConfidence'       => 0.8,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame('2.1', $notes->makerNoteVersion);
        self::assertSame('HDR', $notes->hdrImageType);
        self::assertSame('burst-uuid', $notes->burstUuid);
        self::assertSame([0.45, 1.5], $notes->focusDistanceRange);
        self::assertSame('2', $notes->oisMode);
        self::assertSame('Burst', $notes->imageCaptureType);
        self::assertSame('unique-id', $notes->imageUniqueId);
        self::assertSame('photo-id', $notes->photoIdentifier);
        self::assertEqualsWithDelta(0.75, $notes->afMeasuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.8, $notes->afConfidence, 1e-12);
    }

    /**
     * @param array<int, int>|array{values: list<int>}|int $makerNoteVersion
     */
    #[Test]
    #[DataProvider('makerNoteVersionProvider')]
    public function buildAppleMakerNotesNormalisesMakerNoteVersionFromIntegers(array|int $makerNoteVersion, string $expected): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'normalised-version',
            'MakerNoteVersion'  => $makerNoteVersion,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($expected, $notes->makerNoteVersion);
    }

    /**
     * @return iterable<string, array{array{values: list<int>}|list<int>|int, string}>
     */
    public static function makerNoteVersionProvider(): iterable
    {
        yield 'list of integers' => [[1, 4, 0, 2], '1.4.0.2'];
        yield 'values wrapper' => [['values' => [3, 1]], '3.1'];
        yield 'scalar integer' => [7, '7'];
    }

    #[Test]
    public function buildAppleMakerNotesCombinesFocusDistanceNearAndFar(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier'      => 'focus-range',
            'FocusDistanceRangeNear' => 0.3,
            'FocusDistanceRangeFar'  => 2.8,
            'ImageCaptureType'       => 'Portrait',
            'HDRImageType'           => 'HDR3',
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([0.3, 2.8], $notes->focusDistanceRange);
        self::assertSame('HDR3', $notes->hdrImageType);
        self::assertSame('Portrait', $notes->imageCaptureType);
    }

    #[Test]
    public function buildAppleMakerNotesHandlesFocusDistanceNearOnly(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier'      => 'near-only',
            'FocusDistanceRangeNear' => 0.42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([0.42], $notes->focusDistanceRange);
    }

    #[Test]
    public function buildAppleMakerNotesHandlesFocusDistanceFarOnly(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'far-only',
            'FocusDistanceRangeFar' => '1.75',
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([1.75], $notes->focusDistanceRange);
    }

    #[Test]
    public function buildAppleMakerNotesKeepsUnknownEnumerations(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'unknown',
            'HDRImageType'      => 99,
            'ImageCaptureType'  => 42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame('99', $notes->hdrImageType);
        self::assertSame('42', $notes->imageCaptureType);
    }

    #[Test]
    #[DataProvider('hdrImageTypeProvider')]
    public function buildAppleMakerNotesMapsHdrImageTypeCodes(int $code, string $label): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'hdr-' . $code,
            'HDRImageType'      => $code,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($label, $notes->hdrImageType);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function hdrImageTypeProvider(): iterable
    {
        yield 'Standard' => [0, 'Standard'];
        yield 'HDR' => [1, 'HDR'];
        yield 'HDR2' => [2, 'HDR2'];
        yield 'HDR Image' => [3, 'HDR Image'];
        yield 'Original Image' => [4, 'Original Image'];
    }

    #[Test]
    #[DataProvider('imageCaptureTypeProvider')]
    public function buildAppleMakerNotesMapsImageCaptureTypeCodes(int $code, string $label): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'mapped-' . $code,
            'ImageCaptureType'  => $code,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($label, $notes->imageCaptureType);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function imageCaptureTypeProvider(): iterable
    {
        yield 'ProRAW' => [1, 'ProRAW'];
        yield 'Portrait' => [2, 'Portrait'];
        yield 'Live Photo' => [3, 'Live Photo'];
        yield 'Live Photo Long Exposure' => [4, 'Live Photo Long Exposure'];
        yield 'Burst' => [5, 'Burst'];
        yield 'Night Mode' => [6, 'Night Mode'];
        yield 'Night Mode Portrait' => [7, 'Night Mode Portrait'];
        yield 'Photo' => [10, 'Photo'];
        yield 'Manual Focus' => [11, 'Manual Focus'];
        yield 'Scene' => [12, 'Scene'];
    }

    #[Test]
    public function decodeParsesAdditionalMakerNoteFields(): void
    {
        $raw = '{ MakerNoteVersion = "1.4"; HDRImageType = 2; BurstUUID = "text-burst"; '
            . 'FocusDistanceRange = (0.4, 1.6); OISMode = 5; ImageCaptureType = 4; '
            . 'ImageUniqueID = "text-unique"; PhotoIdentifier = "text-photo"; '
            . 'AFMeasuredDepth = 1.1; AFConfidence = 0.65; ContentIdentifier = "textual"; }';

        $decoder = new AppleDecoder();

        $metadata = $decoder->decode($raw, 'Apple', 'iPhone');
        $apple    = $metadata->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('textual', $apple->contentIdentifier);
        self::assertSame('1.4', $apple->makerNoteVersion);
        self::assertSame('HDR2', $apple->hdrImageType);
        self::assertSame('text-burst', $apple->burstUuid);
        self::assertSame([0.4, 1.6], $apple->focusDistanceRange);
        self::assertSame('5', $apple->oisMode);
        self::assertSame('Live Photo Long Exposure', $apple->imageCaptureType);
        self::assertSame('text-unique', $apple->imageUniqueId);
        self::assertSame('text-photo', $apple->photoIdentifier);
        self::assertEqualsWithDelta(1.1, $apple->afMeasuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.65, $apple->afConfidence, 1e-12);
    }

    #[Test]
    public function buildAppleMakerNotesHandlesCameraTypeCodes(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

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

        $dictionary = [
            'RunTime' => [
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
        $runTime = $notes->runTime;
        self::assertInstanceOf(RunTime::class, $runTime);
        self::assertSame(2, $runTime->epoch);
        self::assertSame(600, $runTime->timescale);
        self::assertSame(1500, $runTime->value);
        self::assertSame(5, $runTime->flags);
    }

    #[Test]
    public function buildAppleMakerNotesExtractsExtendedTags(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier'       => 'extended',
            'AEStable'                => '1',
            'AETarget'                => ['numerator' => 37, 'denominator' => 10],
            'AEAverage'               => [1, 4],
            'AFStable'                => '0',
            'AFPerformance'           => ['value' => ['numerator' => 3, 'denominator' => 2]],
            'SignalToNoiseRatioType'  => '2',
            'LuminanceNoiseAmplitude' => ['values' => [['numerator' => 5, 'denominator' => 2]]],
            'ImageCaptureRequestID'   => '  REQ-12345  ',
            'QualityHint'             => ' LowLight ',
            'ColorCorrectionMatrix'   => ['values' => [1, '0.5', 0.25]],
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertTrue($notes->aeStable);
        self::assertEqualsWithDelta(3.7, $notes->aeTarget, 1e-12);
        self::assertEqualsWithDelta(0.25, $notes->aeAverage, 1e-12);
        self::assertFalse($notes->afStable);
        self::assertEqualsWithDelta(1.5, $notes->afPerformance, 1e-12);
        self::assertSame(2, $notes->signalToNoiseRatioType);
        self::assertEqualsWithDelta(2.5, $notes->luminanceNoiseAmplitude, 1e-12);
        self::assertSame('REQ-12345', $notes->imageCaptureRequestId);
        self::assertSame('LowLight', $notes->qualityHint);
        self::assertSame([1.0, 0.5, 0.25], $notes->colorCorrectionMatrix);
    }

    #[Test]
    #[DataProvider('stabilityFlagProvider')]
    public function buildAppleMakerNotesParsesStabilityFlags(string $makerKey, string|int $value, string $expectedFlag, bool $expected): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        /** @var AppleMakerNotes|null $notes */
        $notes = $method->invoke($decoder, [
            'ContentIdentifier' => 'stability',
            $makerKey           => $value,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertArrayHasKey($expectedFlag, $notes->flags);
        self::assertSame($expected, $notes->flags[$expectedFlag]);
    }

    /**
     * @return iterable<string, array{string, string|int, string, bool}>
     */
    public static function stabilityFlagProvider(): iterable
    {
        yield 'ae-stable numeric enabled' => ['AEStable', 1, 'aeStable', true];
        yield 'ae-stable string disabled' => ['AEStable', '0', 'aeStable', false];
        yield 'af-stable numeric disabled' => ['AFStable', 0, 'afStable', false];
        yield 'af-stable string enabled' => ['AFStable', '1', 'afStable', true];
    }

    private function buildMakerNotesBlobWithMovieIndex(): string
    {
        return str_replace('LivePhotoVideoIndex', 'LivePhotoMovieIndex', $this->buildMakerNotesBlob());
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
        self::assertNull($metadata->apple);
    }

    #[Test]
    public function flagMasksMirrorScalarInputs(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

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
            'PersonInPhoto'         => true,
            'PetInPhoto'            => false,
            'AEStable'              => 1,
            'AFStable'              => '0',
        ]);

        $maskNotes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'mask',
            'SceneFlags'            => (1 << 0) | (1 << 1),
            'ImageProcessingFlags'  => (1 << 0) | (1 << 1),
            'PhotosAppFeatureFlags' => 1 << 0,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $scalarNotes);
        self::assertInstanceOf(AppleMakerNotes::class, $maskNotes);

        $scalarFlags = $scalarNotes->flags;
        $maskFlags   = $maskNotes->flags;

        ksort($maskFlags);

        self::assertSame(
            [
                'hdrAuto'       => true,
                'hdrEnabled'    => true,
                'longExposure'  => true,
                'nightMode'     => true,
                'personInPhoto' => true,
                'petInPhoto'    => false,
            ],
            $maskFlags,
        );

        foreach ($maskFlags as $flag => $value) {
            self::assertArrayHasKey($flag, $scalarFlags);
            self::assertSame($value, $scalarFlags[$flag]);
        }

        self::assertTrue($scalarFlags['livePhoto']);
        self::assertTrue($scalarFlags['livePhotoAuto']);
        self::assertTrue($scalarFlags['livePhotoEnabled']);
        self::assertTrue($scalarFlags['livePhotoActive']);
        self::assertTrue($scalarFlags['livePhotoLongExposure']);
        self::assertTrue($scalarFlags['aeStable']);
        self::assertFalse($scalarFlags['afStable']);
    }

    #[Test]
    public function explicitFlagValuesOverrideMasks(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        $notes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'override',
            'LivePhotoLongExposure' => false,
            'NightMode'             => false,
            'SceneFlags'            => 1 << 0,
            'PetInPhoto'            => false,
            'PhotosAppFeatureFlags' => 1 << 1,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        $flags = $notes->flags;
        ksort($flags);

        self::assertSame(
            [
                'livePhotoLongExposure' => false,
                'longExposure'          => false,
                'nightMode'             => false,
                'personInPhoto'         => false,
                'petInPhoto'            => false,
            ],
            $flags,
        );
    }

    #[Test]
    public function flagMasksAcceptBitPositionLists(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');

        $notes = $method->invoke($decoder, [
            'ContentIdentifier'     => 'positions',
            'SceneFlags'            => [0, 1],
            'ImageProcessingFlags'  => ['values' => [0, 1]],
            'PhotosAppFeatureFlags' => [0],
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);

        $flags = $notes->flags;
        ksort($flags);

        self::assertSame(
            [
                'hdrAuto'       => true,
                'hdrEnabled'    => true,
                'longExposure'  => true,
                'nightMode'     => true,
                'personInPhoto' => true,
                'petInPhoto'    => false,
            ],
            $flags,
        );
    }
}
