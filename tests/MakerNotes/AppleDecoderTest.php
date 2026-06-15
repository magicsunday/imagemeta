<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleAutoExposure;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleAutoFocus;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCameraCapture;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCaptureIdentity;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleFlagExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleJpegIfdParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleLivePhoto;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleNoise;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleRationalNormalizer;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleSemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\Apple\BinaryPlistDecoder;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveUnarchiver;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistBinaryReader;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextCursor;
use MagicSunday\ImageMeta\MakerNotes\Apple\PlistTextParser;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Value\RunTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function hex2bin;
use function sha1;
use function str_repeat;
use function str_replace;
use function strlen;

/**
 * Exercises the Apple maker notes decoder across keyed-archive payloads.
 * It verifies camera type mapping, content identifiers, and runtime extraction.
 * The suite covers scalar, array, and dictionary plist values used in maker notes.
 * This ensures Apple maker notes are decoded into structured records reliably.
 */
#[CoversClass(AppleDecoder::class)]
#[CoversClass(AppleMakerNotesBuilder::class)]
#[UsesClass(AppleAutoExposure::class)]
#[UsesClass(AppleAutoFocus::class)]
#[UsesClass(AppleCameraCapture::class)]
#[UsesClass(AppleCaptureIdentity::class)]
#[UsesClass(AppleHdr::class)]
#[UsesClass(AppleLivePhoto::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleNoise::class)]
#[UsesClass(AppleSemanticStyle::class)]
#[UsesClass(ApplePlistArray::class)]
#[UsesClass(ApplePlistDictionary::class)]
#[UsesClass(ApplePlistScalar::class)]
#[UsesClass(BinaryPlistDecoder::class)]
#[UsesClass(PlistBinaryReader::class)]
#[UsesClass(KeyedArchiveUnarchiver::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(RunTime::class)]
#[UsesClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleFlagExtractor::class)]
#[UsesClass(KeyedArchiveResolver::class)]
#[UsesClass(PlistTextCursor::class)]
#[UsesClass(PlistTextParser::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(AppleJpegIfdParser::class)]
#[UsesClass(AppleRationalNormalizer::class)]
final class AppleDecoderTest extends TestCase
{
    private function decodeAppleMakerNotesRecord(string $raw): MakerNotesRecord
    {
        $decoder = new AppleDecoder();

        return $decoder->decode($raw, 'Apple', 'iPhone');
    }

    private function decodeAppleMakerNotes(string $raw): AppleMakerNotes
    {
        $apple = $this->decodeAppleMakerNotesRecord($raw)->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);

        return $apple;
    }

    /**
     * Decodes a keyed-archive payload that includes camera type information.
     * Ensures AppleDecoder maps the camera type code and extracts the content identifier.
     */
    #[Test]
    public function decodeMapsCameraTypeCodeFromKeyedArchive(): void
    {
        $hex = '62706c6973743030d401020304050622265924617263686976657258246f626a'
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
        $raw   = (string) hex2bin($hex);
        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('archived-photo-uuid', $apple->identity?->contentIdentifier);
        self::assertSame('Front', $apple->camera?->type);
    }

    /**
     * Decodes a keyed archive containing HDR, semantic style, acceleration, and flags.
     * Verifies AppleMakerNotes fields are populated from the archived structure.
     */
    #[Test]
    public function decodeUnarchivesKeyedArchivePayload(): void
    {
        $hex = '62706c6973743030d40102030405066e725924617263686976657258246f626a656374735424746f70582476657273696f6e5f100f4e534b65796564'
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
        $raw   = (string) hex2bin($hex);
        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('archived-photo-uuid', $apple->identity?->contentIdentifier);
        self::assertEqualsWithDelta(3.25, $apple->hdr?->headroom, 1e-12);
        self::assertSame([1.0, 1.5, 1.75], $apple->hdr?->gain);
        self::assertEqualsWithDelta(23.5, $apple->noise?->snr, 1e-12);
        self::assertEqualsWithDelta(0.58, $apple->autoFocus?->focusPosition, 1e-12);
        self::assertSame(5, $apple->livePhoto?->index);
        self::assertSame('DramaticWarm', $apple->semanticStyle?->preset);
        self::assertEqualsWithDelta(0.3, $apple->semanticStyle->warmth, 1e-12);
        self::assertEqualsWithDelta(-0.15, $apple->semanticStyle->tone, 1e-12);
        self::assertSame([0.12, -0.34, 0.56], $apple->livePhoto->accelerationVector);

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

    /**
     * Parses a synthetic maker note property list blob.
     * Ensures the metadata record and AppleMakerNotes fields are populated, including flags.
     */
    #[Test]
    public function decodeParsesAppleMakerNotes(): void
    {
        $raw      = $this->buildMakerNotesBlob();
        $metadata = $this->decodeAppleMakerNotesRecord($raw);
        self::assertSame('Apple', $metadata->vendor);
        self::assertSame(strlen($raw), $metadata->length);
        self::assertSame(sha1($raw), $metadata->sha1);

        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('photo-uuid', $apple->identity?->contentIdentifier);
        self::assertSame('Tele', $apple->camera?->type);
        self::assertEqualsWithDelta(2.5, $apple->hdr?->headroom, 1e-12);
        self::assertSame([1.0, 1.2, 1.3], $apple->hdr?->gain);
        self::assertEqualsWithDelta(24.5, $apple->noise?->snr, 1e-12);
        self::assertEqualsWithDelta(0.62, $apple->autoFocus?->focusPosition, 1e-12);
        self::assertSame(2, $apple->livePhoto?->index);
        self::assertSame(5000, $apple->camera->colorTemperature);
        self::assertSame('Warm', $apple->semanticStyle?->preset);
        self::assertEqualsWithDelta(0.15, $apple->semanticStyle->warmth, 1e-12);
        self::assertEqualsWithDelta(-0.05, $apple->semanticStyle->tone, 1e-12);
        self::assertSame([0.1, -0.2, 0.3], $apple->livePhoto->accelerationVector);

        self::assertTrue($apple->flags['livePhotoAuto']);
        self::assertArrayHasKey('nightMode', $apple->flags);
        self::assertFalse($apple->flags['nightMode']);
    }

    /**
     * Decodes a dictionary-style maker note payload with trailing null padding.
     * Confirms padding is ignored and key fields are parsed successfully.
     */
    #[Test]
    public function decodeAcceptsPaddedDictionaryPayload(): void
    {
        $raw = '{ ContentIdentifier = "padded"; LivePhotoAuto = 1; }' . str_repeat("\0", 8);

        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('padded', $apple->identity?->contentIdentifier);
        self::assertTrue($apple->flags['livePhotoAuto']);
    }

    /**
     * Decodes flag bitmasks where all bits are zero.
     * Ensures all known flags are present and default to false.
     */
    #[Test]
    public function decodeRecordsDisabledFlagsFromZeroBitMasks(): void
    {
        $raw = '{ ContentIdentifier = "flags-zero"; SceneFlags = 0; ImageProcessingFlags = 0; PhotosAppFeatureFlags = 0; }';

        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('flags-zero', $apple->identity?->contentIdentifier);

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

    /**
     * Decodes a maker note payload that uses the LivePhotoMovieIndex key.
     * Verifies the decoder exposes the live photo index as an integer.
     */
    #[Test]
    public function decodeResolvesLivePhotoMovieIndex(): void
    {
        $raw   = $this->buildMakerNotesBlobWithMovieIndex();
        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame(2, $apple->livePhoto?->index);
    }

    /**
     * Parses a compact semantic style array from a binary plist payload.
     * Ensures semantic style preset, warmth, and tone are extracted correctly.
     */
    #[Test]
    public function decodeMapsSemanticStyleFromCompactArray(): void
    {
        $raw   = (string) hex2bin('62706c6973743030d2010203045f1011436f6e74656e744964656e7469666965725d53656d616e7469635374796c655d636f6d706163742d7374796c65d405060708090a0b0c525f30525f31525f32525f33555669766964233fd000000000000023bfb999999999999a1001080d212f3d46494c4f5258616a0000000000000101000000000000000d0000000000000000000000000000006c');
        $apple = $this->decodeAppleMakerNotes($raw);
        self::assertSame('compact-style', $apple->identity?->contentIdentifier);
        self::assertSame('Vivid', $apple->semanticStyle?->preset);
        self::assertEqualsWithDelta(0.25, $apple->semanticStyle->warmth, 1e-12);
        self::assertEqualsWithDelta(-0.1, $apple->semanticStyle->tone, 1e-12);
    }

    /**
     * Builds AppleMakerNotes from a dictionary that includes extended maker note fields.
     * Confirms HDR, burst, focus range, OIS, and AF fields are mapped and normalized.
     */
    #[Test]
    public function buildAppleMakerNotesExtractsAdditionalFields(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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
        self::assertSame('2.1', $notes->camera?->makerNoteVersion);
        self::assertSame('HDR', $notes->hdr?->imageType);
        self::assertSame('burst-uuid', $notes->identity?->burstUuid);
        self::assertSame([0.45, 1.5], $notes->autoFocus?->focusDistanceRange);
        self::assertSame('2', $notes->camera->oisMode);
        self::assertSame('Burst', $notes->camera->imageCaptureType);
        self::assertSame('unique-id', $notes->identity->imageUniqueId);
        self::assertSame('photo-id', $notes->identity->photoIdentifier);
        self::assertEqualsWithDelta(0.75, $notes->autoFocus->measuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.8, $notes->autoFocus->confidence, 1e-12);
    }

    /**
     * Normalizes MakerNoteVersion values supplied as integers, lists, or values wrappers.
     * Ensures buildAppleMakerNotes emits a dot-separated version string for each input form.
     *
     * @param array<int, int>|array{values: list<int>}|int $makerNoteVersion
     */
    #[Test]
    #[DataProvider('makerNoteVersionProvider')]
    public function buildAppleMakerNotesNormalizesMakerNoteVersionFromIntegers(array|int $makerNoteVersion, string $expected): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier' => 'normalized-version',
            'MakerNoteVersion'  => $makerNoteVersion,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($expected, $notes->camera?->makerNoteVersion);
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

    /**
     * Combines FocusDistanceRangeNear and FocusDistanceRangeFar into a single range.
     * Verifies the range is preserved while related fields remain mapped.
     */
    #[Test]
    public function buildAppleMakerNotesCombinesFocusDistanceNearAndFar(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier'      => 'focus-range',
            'FocusDistanceRangeNear' => 0.3,
            'FocusDistanceRangeFar'  => 2.8,
            'ImageCaptureType'       => 'Portrait',
            'HDRImageType'           => 'HDR3',
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([0.3, 2.8], $notes->autoFocus?->focusDistanceRange);
        self::assertSame('HDR3', $notes->hdr?->imageType);
        self::assertSame('Portrait', $notes->camera?->imageCaptureType);
    }

    /**
     * Provides only FocusDistanceRangeNear in the maker note dictionary.
     * Ensures the builder returns a single-element focus distance range.
     */
    #[Test]
    public function buildAppleMakerNotesHandlesFocusDistanceNearOnly(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier'      => 'near-only',
            'FocusDistanceRangeNear' => 0.42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([0.42], $notes->autoFocus?->focusDistanceRange);
    }

    /**
     * Provides only FocusDistanceRangeFar as a numeric string.
     * Confirms the builder parses it into a single-element focus distance range.
     */
    #[Test]
    public function buildAppleMakerNotesHandlesFocusDistanceFarOnly(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier'     => 'far-only',
            'FocusDistanceRangeFar' => '1.75',
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame([1.75], $notes->autoFocus?->focusDistanceRange);
    }

    /**
     * Supplies HDRImageType and ImageCaptureType codes outside the known mappings.
     * Ensures unknown values are preserved as strings without conversion.
     */
    #[Test]
    public function buildAppleMakerNotesKeepsUnknownEnumerations(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier' => 'unknown',
            'HDRImageType'      => 99,
            'ImageCaptureType'  => 42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame('99', $notes->hdr?->imageType);
        self::assertSame('42', $notes->camera?->imageCaptureType);
    }

    /**
     * Maps HDR image type codes provided via the data provider.
     * Verifies each code resolves to the expected label.
     */
    #[Test]
    #[DataProvider('hdrImageTypeProvider')]
    public function buildAppleMakerNotesMapsHdrImageTypeCodes(int $code, string $label): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier' => 'hdr-' . $code,
            'HDRImageType'      => $code,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($label, $notes->hdr?->imageType);
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

    /**
     * Maps image capture type codes using the data provider.
     * Ensures each code is translated into the correct capture type label.
     */
    #[Test]
    #[DataProvider('imageCaptureTypeProvider')]
    public function buildAppleMakerNotesMapsImageCaptureTypeCodes(int $code, string $label): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
            'ContentIdentifier' => 'mapped-' . $code,
            'ImageCaptureType'  => $code,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame($label, $notes->camera?->imageCaptureType);
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

    /**
     * Decodes a textual maker note payload containing additional tagged fields.
     * Confirms version, HDR type, burst info, focus range, and AF metrics are parsed.
     */
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
        self::assertSame('textual', $apple->identity?->contentIdentifier);
        self::assertSame('1.4', $apple->camera?->makerNoteVersion);
        self::assertSame('HDR2', $apple->hdr?->imageType);
        self::assertSame('text-burst', $apple->identity->burstUuid);
        self::assertSame([0.4, 1.6], $apple->autoFocus?->focusDistanceRange);
        self::assertSame('5', $apple->camera->oisMode);
        self::assertSame('Live Photo Long Exposure', $apple->camera->imageCaptureType);
        self::assertSame('text-unique', $apple->identity->imageUniqueId);
        self::assertSame('text-photo', $apple->identity->photoIdentifier);
        self::assertEqualsWithDelta(1.1, $apple->autoFocus->measuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.65, $apple->autoFocus->confidence, 1e-12);
    }

    /**
     * Supplies known and unknown camera type codes in the maker note dictionary.
     * Ensures known codes map to labels while unknown codes remain numeric.
     */
    #[Test]
    public function buildAppleMakerNotesHandlesCameraTypeCodes(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $mapped = $builder->build([
            'ContentIdentifier' => 'mapped-camera',
            'CameraType'        => 0,
        ]);

        $unknown = $builder->build([
            'ContentIdentifier' => 'unknown-camera',
            'CameraType'        => 42,
        ]);

        self::assertInstanceOf(AppleMakerNotes::class, $mapped);
        self::assertInstanceOf(AppleMakerNotes::class, $unknown);
        self::assertSame('Back Wide Angle', $mapped->camera?->type);
        self::assertSame(42, $unknown->camera?->type);
    }

    /**
     * Provides a SemanticStyle dictionary using a values wrapper structure.
     * Verifies the builder falls back to dictionary parsing for semantic style fields.
     */
    #[Test]
    public function buildAppleMakerNotesFallsBackToSemanticStyleDictionary(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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
        self::assertSame('DictionaryPreset', $notes->semanticStyle?->preset);
        self::assertEqualsWithDelta(0.45, $notes->semanticStyle->warmth, 1e-12);
        self::assertEqualsWithDelta(-0.25, $notes->semanticStyle->tone, 1e-12);
    }

    /**
     * Supplies a RunTime dictionary alongside LivePhotoVideoIndex.
     * Ensures run-time data is converted to a RunTime object and livePhotoTime is computed.
     */
    #[Test]
    public function buildAppleMakerNotesParsesRunTime(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $dictionary = [
            'RunTime' => [
                'epoch'     => '2',
                'timescale' => 600,
                'value'     => 1500,
                'flags'     => 5,
            ],
            'LivePhotoVideoIndex' => 1200,
        ];

        $notes = $builder->build($dictionary);

        self::assertInstanceOf(AppleMakerNotes::class, $notes);
        self::assertSame(1200, $notes->livePhoto?->index);
        self::assertEqualsWithDelta(2.0, $notes->livePhoto->time, 1e-12);
        $runTime = $notes->livePhoto->runTime;
        self::assertInstanceOf(RunTime::class, $runTime);
        self::assertSame(2, $runTime->epoch);
        self::assertSame(600, $runTime->timescale);
        self::assertSame(1500, $runTime->value);
        self::assertSame(5, $runTime->flags);
    }

    /**
     * Provides extended AE/AF and quality fields in multiple numeric representations.
     * Confirms the builder normalizes these values and trims string fields.
     */
    #[Test]
    public function buildAppleMakerNotesExtractsExtendedTags(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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
        self::assertTrue($notes->autoExposure?->stable);
        self::assertEqualsWithDelta(3.7, $notes->autoExposure->target, 1e-12);
        self::assertEqualsWithDelta(0.25, $notes->autoExposure->average, 1e-12);
        self::assertFalse($notes->autoFocus?->stable);
        self::assertEqualsWithDelta(1.5, $notes->autoFocus->performance, 1e-12);
        self::assertSame(2, $notes->noise?->signalToNoiseRatioType);
        self::assertEqualsWithDelta(2.5, $notes->noise->luminanceAmplitude, 1e-12);
        self::assertSame('REQ-12345', $notes->identity?->imageCaptureRequestId);
        self::assertSame('LowLight', $notes->camera?->qualityHint);
        self::assertSame([1.0, 0.5, 0.25], $notes->camera->colorCorrectionMatrix);
    }

    /**
     * Uses the data provider to supply AE/AF stability flags in different formats.
     * Ensures the corresponding boolean flags are set as expected.
     */
    #[Test]
    #[DataProvider('stabilityFlagProvider')]
    public function buildAppleMakerNotesParsesStabilityFlags(string $makerKey, string|int $value, string $expectedFlag, bool $expected): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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

    /**
     * Provides a non-plist string payload that should not be decoded.
     * Ensures the decoder returns metadata without Apple maker notes.
     */
    #[Test]
    public function decodeIgnoresUnsupportedPropertyListFormat(): void
    {
        $decoder = new AppleDecoder();

        $metadata = $decoder->decode('Apple iOS' . str_repeat("\x00", 32), 'Apple', 'iPhone');
        self::assertNull($metadata->apple);
    }

    /**
     * Compares scalar flag inputs against equivalent bitmask-derived flags.
     * Verifies both approaches yield matching normalized flag values.
     */
    #[Test]
    public function flagMasksMirrorScalarInputs(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $scalarNotes = $builder->build([
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

        $maskNotes = $builder->build([
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

    /**
     * Combines explicit boolean flags with bitmask-derived flags.
     * Ensures explicit values override mask-derived defaults.
     */
    #[Test]
    public function explicitFlagValuesOverrideMasks(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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

    /**
     * Supplies bit position lists for the flag masks.
     * Confirms the decoder accepts list-based masks and maps them to booleans.
     */
    #[Test]
    public function flagMasksAcceptBitPositionLists(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $notes = $builder->build([
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
