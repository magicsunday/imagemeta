<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\Dji\DjiTelemetry;
use MagicSunday\ImageMeta\Parse\IsoBmff\DjiMdatTelemetryScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * Tests the DjiMdatTelemetryScanner for extracting DJI telemetry from mdat streams.
 */
#[CoversClass(DjiMdatTelemetryScanner::class)]
#[UsesClass(DjiTelemetry::class)]
#[UsesClass(Stream::class)]
final class DjiMdatTelemetryScannerTest extends TestCase
{
    /**
     * Builds a minimal DJI protobuf record containing model name and GPS coordinates.
     *
     * The record structure mirrors real DJI telemetry:
     * - field 4 (length-delimited): model name string (e.g. "DJI FC8671")
     * - field 5 (fixed32): framerate as float
     * - field 6 (varint): timestamp
     * Then a second message with GPS in a nested sub-message.
     */
    private function buildDjiProtobufRecord(string $model, float $latRadians, float $lonRadians): string
    {
        // First message: model + framerate + timestamp
        $modelBytes = '"' . chr(strlen($model)) . $model; // field 4, wire 2
        $framerate  = "\x2D" . pack('g', 29.97);             // field 5, wire 5
        $timestamp  = "\x30\xAC\x02";                        // field 6, wire 0, varint 300

        // GPS sub-message: field 2 = f64 lat, field 3 = f64 lon
        $gpsInner   = "\x11" . pack('e', $latRadians)   // field 2, wire 1 (fixed64)
                  . "\x19" . pack('e', $lonRadians);   // field 3, wire 1 (fixed64)

        // Wrap GPS in field 1 (length-delimited) of outer GPS message
        $gpsOuter   = "\x0A" . chr(strlen($gpsInner)) . $gpsInner;

        // Altitude: field 2, varint (in some unit)
        $altitude   = "\x10\x86\xA0\x17"; // varint 380934

        // GPS message wrapped as field 4 (length-delimited) of the second message block
        $gpsMsg     = $gpsOuter . $altitude;
        $field4     = '"' . chr(strlen($gpsMsg)) . $gpsMsg;

        return $modelBytes . $framerate . $timestamp . $field4;
    }

    #[Test]
    public function scanExtractsModelFromMdatWithDjiRecord(): void
    {
        $record  = $this->buildDjiProtobufRecord('DJI FC8671', 0.894425, 0.223173);
        $mdat    = str_repeat("\x00", 1000) . $record . str_repeat("\x00", 100);

        $scanner = new DjiMdatTelemetryScanner();
        $result  = $scanner->scanBytes($mdat);

        self::assertNotNull($result);
        self::assertSame('DJI FC8671', $result->model);
    }

    #[Test]
    public function scanExtractsGpsCoordinates(): void
    {
        $latRad      = 0.894425;
        $lonRad      = 0.223173;

        $record      = $this->buildDjiProtobufRecord('DJI FC8671', $latRad, $lonRad);
        $mdat        = str_repeat("\x00", 500) . $record . str_repeat("\x00", 50);

        $scanner     = new DjiMdatTelemetryScanner();
        $result      = $scanner->scanBytes($mdat);

        self::assertNotNull($result);

        // GPS should be converted from radians to degrees
        $expectedLat = $latRad * 180.0 / M_PI;
        $expectedLon = $lonRad * 180.0 / M_PI;

        self::assertEqualsWithDelta($expectedLat, $result->latitude, 0.001);
        self::assertEqualsWithDelta($expectedLon, $result->longitude, 0.001);
    }

    #[Test]
    public function scanReturnsNullWhenNoDjiSignaturePresent(): void
    {
        $mdat    = str_repeat("\x00", 2000);

        $scanner = new DjiMdatTelemetryScanner();
        $result  = $scanner->scanBytes($mdat);

        self::assertNull($result);
    }

    #[Test]
    public function scanReturnsNullForEmptyInput(): void
    {
        $scanner = new DjiMdatTelemetryScanner();

        self::assertNull($scanner->scanBytes(''));
    }

    #[Test]
    public function scanHandlesDjiStringNotInProtobuf(): void
    {
        // "DJI " appears but not in a valid protobuf context
        $mdat    = str_repeat("\x00", 100) . 'DJI FC8671' . str_repeat("\xFF", 100);

        $scanner = new DjiMdatTelemetryScanner();
        $result  = $scanner->scanBytes($mdat);

        // Should still detect the model name even without valid GPS
        self::assertNotNull($result);
        self::assertSame('DJI FC8671', $result->model);
    }

    #[Test]
    public function scanStreamExtractsFromTail(): void
    {
        $record  = $this->buildDjiProtobufRecord('DJI FC8671', 0.894425, 0.223173);
        $data    = str_repeat("\x00", 500) . $record . str_repeat("\x00", 50);

        $handle  = fopen('php://temp', 'wb+');
        self::assertNotFalse($handle);

        fwrite($handle, $data);
        rewind($handle);

        $stream  = new Stream($handle, strlen($data));
        $scanner = new DjiMdatTelemetryScanner();
        $result  = $scanner->scanStream($stream);

        self::assertNotNull($result);
        self::assertSame('DJI FC8671', $result->model);
        self::assertNotNull($result->latitude);
        self::assertNotNull($result->longitude);
    }

    #[Test]
    public function scanExtractsFromMultipleRecords(): void
    {
        $record1 = $this->buildDjiProtobufRecord('DJI FC8671', 0.5, 0.3);
        $record2 = $this->buildDjiProtobufRecord('DJI FC8671', 0.894425, 0.223173);

        $mdat    = str_repeat("\x00", 200)
              . $record1
              . str_repeat("\x00", 50)
              . $record2
              . str_repeat("\x00", 50);

        $scanner = new DjiMdatTelemetryScanner();
        $result  = $scanner->scanBytes($mdat);

        self::assertNotNull($result);
        self::assertSame('DJI FC8671', $result->model);
        // Should have GPS from one of the records
        self::assertNotNull($result->latitude);
        self::assertNotNull($result->longitude);
    }
}
