<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Stream;
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

/**
 * Tests SOS/SOF component count validation in JpegFrameValidator,
 * particularly the progressive JPEG (SOF2) allowance for fewer SOS
 * components than SOF declares.
 *
 * @internal
 */
#[CoversClass(JpegFrameValidator::class)]
#[UsesClass(Marker::class)]
#[UsesClass(Stream::class)]
#[UsesClass(JpegMarkerScanner::class)]
#[UsesClass(JpegParserConfig::class)]
final class JpegFrameValidatorTest extends TestCase
{
    /**
     * Progressive JPEG (SOF2) legitimately encodes fewer components per SOS scan
     * than the SOF frame header declares. The validator must accept this.
     *
     * ITU-T T.81 §B.2.3, §G.1.2 — progressive mode allows partial component scans.
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
     * ITU-T T.81 §B.2.3 — non-progressive scans must include all components.
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
