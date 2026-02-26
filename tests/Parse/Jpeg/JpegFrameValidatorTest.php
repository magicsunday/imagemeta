<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\BitMask;
use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegFrameValidator;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegMarkerScanner;
use MagicSunday\ImageMeta\Parse\Jpeg\JpegParserConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function chr;
use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function strlen;

/**
 * Tests SOF parsing, SOS header validation, YCbCr subsampling derivation,
 * and frame state management in JpegFrameValidator.
 *
 * ITU-T T.81 section B.2.2/B.2.3 and EXIF 3.0 section 4.7 define the
 * marker flow requirements checked here.
 *
 * @internal
 */
#[CoversClass(JpegFrameValidator::class)]
#[UsesClass(BitMask::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(Marker::class)]
#[UsesClass(PayloadGuard::class)]
#[UsesClass(Stream::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(JpegMarkerScanner::class)]
#[UsesClass(JpegParserConfig::class)]
final class JpegFrameValidatorTest extends TestCase
{
    /**
     * Progressive JPEG (SOF2) legitimately encodes fewer components per SOS scan
     * than the SOF frame header declares. The validator must accept this.
     *
     * ITU-T T.81 section B.2.3 / G.1.2 -- progressive mode allows partial component scans.
     */
    #[Test]
    public function acceptsFewerSosComponentsThanSofForProgressiveJpeg(): void
    {
        $validator = $this->createValidator();

        // SOF2 payload: 8-bit precision, 100 lines, 100 samples, 3 components (Y=1, Cb=2, Cr=3)
        $sofPayload = chr(8)                      // precision
            . pack('n', 100)                      // lines
            . pack('n', 100)                      // samples per line
            . chr(3)                              // component count
            . chr(1) . chr(0x22) . chr(0)         // Y:  id=1, H=2 V=2, quant=0
            . chr(2) . chr(0x11) . chr(1)         // Cb: id=2, H=1 V=1, quant=1
            . chr(3) . chr(0x11) . chr(1);        // Cr: id=3, H=1 V=1, quant=1

        $validator->handleStartOfFrame(Marker::SOF2, $sofPayload, 0);

        // SOS with only 1 component (Y) — valid for progressive scan
        $sosPayload = chr(1)                      // 1 component
            . chr(1) . chr(0x00)                  // selector=1 (Y), Td=0/Ta=0
            . chr(0) . chr(63) . chr(0);          // Ss=0, Se=63, Ah=0/Al=0

        // Must not throw — assert frame state is still intact
        $validator->validateSosHeader($sosPayload, 100);
        self::assertNotNull($validator->getFrameComponentSampling());
    }

    /**
     * Baseline JPEG (SOF0) requires the SOS component count to match
     * the SOF component count exactly.
     *
     * ITU-T T.81 section B.2.3 -- non-progressive scans must include all components.
     */
    #[Test]
    public function throwsWhenBaselineSosComponentCountMismatchesSof(): void
    {
        $validator = $this->createValidator();

        // SOF0 payload: 3 components
        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(3)
            . chr(1) . chr(0x22) . chr(0)
            . chr(2) . chr(0x11) . chr(1)
            . chr(3) . chr(0x11) . chr(1);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        // SOS with only 1 component — invalid for baseline
        $sosPayload = chr(1)
            . chr(1) . chr(0x00)
            . chr(0) . chr(63) . chr(0);

        $this->expectException(ParseError::class);
        $validator->validateSosHeader($sosPayload, 100);
    }

    /**
     * Parses a valid SOF0 payload and populates frame state getters.
     */
    #[Test]
    public function parsesValidSofAndPopulatesFrameState(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)                      // 8-bit precision
            . pack('n', 480)                      // lines
            . pack('n', 640)                      // samples per line
            . chr(3)                              // 3 components
            . chr(1) . chr(0x22) . chr(0)         // Y:  H=2 V=2
            . chr(2) . chr(0x11) . chr(1)         // Cb: H=1 V=1
            . chr(3) . chr(0x11) . chr(1);        // Cr: H=1 V=1

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        self::assertSame(8, $validator->getFrameBitsPerSample());
        self::assertSame(480, $validator->getFrameLines());
        self::assertSame(640, $validator->getFrameSamplesPerLine());

        $sampling = $validator->getFrameComponentSampling();
        self::assertNotNull($sampling);
        self::assertSame(['horizontal' => 2, 'vertical' => 2], $sampling[1]);
        self::assertSame(['horizontal' => 1, 'vertical' => 1], $sampling[2]);

        // YCbCr 4:2:0 -> [2, 2]
        self::assertSame([2, 2], $validator->getFrameYCbCrSubSampling());
    }

    /**
     * Derives YCbCr 4:2:2 subsampling from appropriate SOF component factors.
     */
    #[Test]
    public function derivesYCbCr422SubSampling(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 200)
            . chr(3)
            . chr(1) . chr(0x21) . chr(0)         // Y:  H=2 V=1
            . chr(2) . chr(0x11) . chr(1)          // Cb: H=1 V=1
            . chr(3) . chr(0x11) . chr(1);         // Cr: H=1 V=1

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        self::assertSame([2, 1], $validator->getFrameYCbCrSubSampling());
    }

    /**
     * Derives YCbCr 4:4:4 (1,1) subsampling from uniform factors.
     */
    #[Test]
    public function derivesYCbCr444SubSampling(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 200)
            . chr(3)
            . chr(1) . chr(0x11) . chr(0)         // Y:  H=1 V=1
            . chr(2) . chr(0x11) . chr(1)          // Cb: H=1 V=1
            . chr(3) . chr(0x11) . chr(1);         // Cr: H=1 V=1

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        self::assertSame([1, 1], $validator->getFrameYCbCrSubSampling());
    }

    /**
     * Throws ParseError when SOF payload is too short.
     */
    #[Test]
    public function throwsWhenSofPayloadTooShort(): void
    {
        $validator = $this->createValidator();

        // Only 5 bytes, need at least 6
        $sofPayload = chr(8) . pack('n', 100) . pack('n', 100);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1283);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);
    }

    /**
     * Throws ParseError when SOF reports zero components.
     */
    #[Test]
    public function throwsWhenSofReportsZeroComponents(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(0);                             // zero components

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1284);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);
    }

    /**
     * Throws ParseError when SOF contains duplicate component identifiers.
     */
    #[Test]
    public function throwsWhenSofContainsDuplicateComponentIds(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(2)
            . chr(1) . chr(0x11) . chr(0)         // component id=1
            . chr(1) . chr(0x11) . chr(1);         // duplicate id=1

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1500);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);
    }

    /**
     * Throws ParseError when SOF contains zero sampling factor.
     */
    #[Test]
    public function throwsWhenSofContainsZeroSamplingFactor(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(1)
            . chr(1) . chr(0x10) . chr(0);        // H=1, V=0 (invalid)

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1286);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);
    }

    /**
     * Ignores a second SOF frame once frame state is already populated.
     */
    #[Test]
    public function ignoresSecondSofAfterFirstIsProcessed(): void
    {
        $validator = $this->createValidator();

        $firstSof = chr(8)
            . pack('n', 480)
            . pack('n', 640)
            . chr(1)
            . chr(1) . chr(0x11) . chr(0);

        $secondSof = chr(12)
            . pack('n', 1000)
            . pack('n', 2000)
            . chr(1)
            . chr(1) . chr(0x11) . chr(0);

        $validator->handleStartOfFrame(Marker::SOF0, $firstSof, 0);
        $validator->handleStartOfFrame(Marker::SOF0, $secondSof, 100);

        self::assertSame(8, $validator->getFrameBitsPerSample());
        self::assertSame(480, $validator->getFrameLines());
        self::assertSame(640, $validator->getFrameSamplesPerLine());
    }

    /**
     * Throws ParseError when SOS header is too short.
     */
    #[Test]
    public function throwsWhenSosHeaderTooShort(): void
    {
        $validator = $this->createValidator();

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2040);

        $validator->validateSosHeader("\x01\x01", 0);
    }

    /**
     * Throws ParseError when SOS declares zero components.
     */
    #[Test]
    public function throwsWhenSosDeclaresZeroComponents(): void
    {
        $validator = $this->createValidator();

        // Zero components + 3 trailing bytes = 4 bytes, but still <6
        $sosPayload = chr(0)
            . chr(0) . chr(0) . chr(0)
            . chr(0) . chr(0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2041);

        $validator->validateSosHeader($sosPayload, 0);
    }

    /**
     * Throws ParseError when SOS references a component not declared in SOF.
     */
    #[Test]
    public function throwsWhenSosReferencesUnknownComponent(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(1)
            . chr(1) . chr(0x11) . chr(0);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        // SOS referencing component 99, which is not in SOF
        $sosPayload = chr(1)
            . chr(99) . chr(0x00)
            . chr(0) . chr(63) . chr(0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2030);

        $validator->validateSosHeader($sosPayload, 100);
    }

    /**
     * Throws ParseError when SOS contains duplicate component selectors.
     */
    #[Test]
    public function throwsWhenSosContainsDuplicateComponentSelector(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(2)
            . chr(1) . chr(0x11) . chr(0)
            . chr(2) . chr(0x11) . chr(1);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        // SOS with duplicate selector for component 1
        $sosPayload = chr(2)
            . chr(1) . chr(0x00)
            . chr(1) . chr(0x00)
            . chr(0) . chr(63) . chr(0);

        $this->expectException(ParseError::class);
        $this->expectExceptionCode(2032);

        $validator->validateSosHeader($sosPayload, 100);
    }

    /**
     * Identifies all defined SOF marker codes as start-of-frame markers.
     */
    #[Test]
    public function identifiesAllSofMarkerCodes(): void
    {
        $validator = $this->createValidator();

        $sofMarkers = [
            Marker::SOF0, Marker::SOF1, Marker::SOF2, Marker::SOF3,
            Marker::SOF5, Marker::SOF6, Marker::SOF7,
            Marker::SOF9, Marker::SOF10, Marker::SOF11,
            Marker::SOF13, Marker::SOF14, Marker::SOF15,
        ];

        foreach ($sofMarkers as $marker) {
            self::assertTrue($validator->isStartOfFrameMarker($marker));
        }

        // Non-SOF markers must return false
        self::assertFalse($validator->isStartOfFrameMarker(Marker::SOS));
        self::assertFalse($validator->isStartOfFrameMarker(Marker::DQT));
        self::assertFalse($validator->isStartOfFrameMarker(Marker::APP1));
    }

    /**
     * Reset clears all frame state.
     */
    #[Test]
    public function resetClearsFrameState(): void
    {
        $validator = $this->createValidator();

        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 200)
            . chr(3)
            . chr(1) . chr(0x22) . chr(0)
            . chr(2) . chr(0x11) . chr(1)
            . chr(3) . chr(0x11) . chr(1);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);
        self::assertNotNull($validator->getFrameBitsPerSample());

        $validator->reset();

        self::assertNull($validator->getFrameBitsPerSample());
        self::assertNull($validator->getFrameLines());
        self::assertNull($validator->getFrameSamplesPerLine());
        self::assertNull($validator->getFrameComponentSampling());
        self::assertNull($validator->getFrameYCbCrSubSampling());
    }

    /**
     * Validates SOS segment through the scanner dependency via validateSosSegment.
     */
    #[Test]
    public function validatesSosSegmentThroughScanner(): void
    {
        // Build a stream with a real SOS segment length + payload after the SOI marker
        $sosPayload = chr(3)                      // 3 components
            . chr(1) . chr(0x00)
            . chr(2) . chr(0x11)
            . chr(3) . chr(0x11)
            . chr(0) . chr(63) . chr(0);

        $sosSegment = pack('n', strlen($sosPayload) + 2) . $sosPayload;

        $fh = fopen('php://memory', 'rb+');
        self::assertIsResource($fh);

        fwrite($fh, $sosSegment);
        rewind($fh);

        $stream    = new Stream($fh, strlen($sosSegment));
        $config    = new JpegParserConfig();
        $scanner   = new JpegMarkerScanner($stream, $config);
        $validator = new JpegFrameValidator($scanner);

        // Set up SOF state so SOS validation can check component selectors
        $sofPayload = chr(8)
            . pack('n', 100)
            . pack('n', 100)
            . chr(3)
            . chr(1) . chr(0x22) . chr(0)
            . chr(2) . chr(0x11) . chr(1)
            . chr(3) . chr(0x11) . chr(1);

        $validator->handleStartOfFrame(Marker::SOF0, $sofPayload, 0);

        // Should not throw
        $validator->validateSosSegment(0);
        self::assertNotNull($validator->getFrameComponentSampling());
    }

    private function createValidator(): JpegFrameValidator
    {
        $fh = fopen('php://memory', 'rb+');
        self::assertIsResource($fh);

        fwrite($fh, "\xFF\xD8");
        rewind($fh);

        $stream  = new Stream($fh, 2);
        $config  = new JpegParserConfig();
        $scanner = new JpegMarkerScanner($stream, $config);

        return new JpegFrameValidator($scanner);
    }
}
