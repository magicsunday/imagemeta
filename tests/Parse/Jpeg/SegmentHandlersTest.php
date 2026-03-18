<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\Stream;
use MagicSunday\ImageMeta\Model\Jpeg\Marker;
use MagicSunday\ImageMeta\Parse\Jpeg\AudioStreamHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\ExifSegmentHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\FlashPixHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\IccProfileHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\IptcSegmentHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\JfifSegmentHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\MpfDocumentHandler;
use MagicSunday\ImageMeta\Parse\Jpeg\XmpSegmentHandler;
use MagicSunday\ImageMeta\Tests\Core\CreatesTempStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Verifies marker-signature gating for concrete JPEG segment handlers.
 */
#[CoversClass(ExifSegmentHandler::class)]
#[CoversClass(XmpSegmentHandler::class)]
#[CoversClass(IccProfileHandler::class)]
#[CoversClass(AudioStreamHandler::class)]
#[CoversClass(MpfDocumentHandler::class)]
#[CoversClass(FlashPixHandler::class)]
#[CoversClass(IptcSegmentHandler::class)]
#[CoversClass(JfifSegmentHandler::class)]
#[UsesClass(Stream::class)]
#[UsesClass(Marker::class)]
#[UsesTrait(CreatesTempStream::class)]
final class SegmentHandlersTest extends TestCase
{
    use CreatesTempStream;

    /**
     * Calls APP1 EXIF callback only for Exif APP1 payloads.
     */
    #[Test]
    public function exifHandlerFiltersByMarkerAndSignature(): void
    {
        $stream  = new Stream($this->createTempStream('JPEG'), 4);
        $hits    = 0;
        $handler = new ExifSegmentHandler(function (string $_payload, int $_offset) use (&$hits): void {
            ++$hits;
        });

        $handler->handle($stream, "Exif\0\0payload", 5);
        $handler->handle($stream, "http://ns.adobe.com/xap/1.0/\0packet", 6);

        self::assertTrue($handler->canHandle(Marker::APP1));
        self::assertFalse($handler->canHandle(Marker::APP2));
        self::assertSame(1, $hits);
    }

    /**
     * Filters XMP and Extended XMP APP1 payloads.
     */
    #[Test]
    public function xmpHandlerAcceptsBaseAndExtendedSignatures(): void
    {
        $stream  = new Stream($this->createTempStream('JPEG'), 4);
        $hits    = 0;
        $handler = new XmpSegmentHandler(function (string $_payload, int $_offset) use (&$hits): void {
            ++$hits;
        });

        $handler->handle($stream, "http://ns.adobe.com/xap/1.0/\0packet", 1);
        $handler->handle($stream, "http://ns.adobe.com/xmp/extension/\0chunk", 2);
        $handler->handle($stream, "Exif\0\0payload", 3);

        self::assertSame(2, $hits);
    }

    /**
     * Verifies APP2 family handlers route only their own signatures.
     */
    #[Test]
    public function app2HandlersFilterByDedicatedSignatures(): void
    {
        $stream = new Stream($this->createTempStream('JPEG'), 4);
        $icc    = 0;
        $audio  = 0;
        $mpf    = 0;
        $fpx    = 0;

        $iccHandler = new IccProfileHandler(function (string $_payload, int $_offset) use (&$icc): void {
            ++$icc;
        });
        $audioHandler = new AudioStreamHandler(function (string $_payload, int $_offset) use (&$audio): void {
            ++$audio;
        });
        $mpfHandler = new MpfDocumentHandler(function (string $_payload, int $_offset) use (&$mpf): void {
            ++$mpf;
        });
        $flashPixHandler = new FlashPixHandler(function (string $_payload, int $_offset) use (&$fpx): void {
            ++$fpx;
        });

        $iccHandler->handle($stream, "ICC_PROFILE\0\x01\x01chunk", 10);
        $audioHandler->handle($stream, "Exif\0\0Audio\x01\x00...", 11);
        $mpfHandler->handle($stream, "MPF\0payload", 12);
        $flashPixHandler->handle($stream, "FPXR\0\0payload", 13);

        $iccHandler->handle($stream, "MPF\0payload", 14);
        $audioHandler->handle($stream, "ICC_PROFILE\0\x01\x01chunk", 15);
        $mpfHandler->handle($stream, "Exif\0\0Audio\x01\x00...", 16);
        $flashPixHandler->handle($stream, "MPF\0payload", 17);

        self::assertTrue($iccHandler->canHandle(Marker::APP2));
        self::assertTrue($audioHandler->canHandle(Marker::APP2));
        self::assertTrue($mpfHandler->canHandle(Marker::APP2));
        self::assertTrue($flashPixHandler->canHandle(Marker::APP2));
        self::assertSame(1, $icc);
        self::assertSame(1, $audio);
        self::assertSame(1, $mpf);
        self::assertSame(1, $fpx);
    }

    /**
     * Verifies APP13 IPTC routing.
     */
    #[Test]
    public function iptcHandlerFiltersByPhotoshopSignature(): void
    {
        $stream  = new Stream($this->createTempStream('JPEG'), 4);
        $hits    = 0;
        $handler = new IptcSegmentHandler(function (string $_payload, int $_offset) use (&$hits): void {
            ++$hits;
        });

        $handler->handle($stream, "Photoshop 3.0\0\0...", 40);
        $handler->handle($stream, 'not-iptc', 41);

        self::assertTrue($handler->canHandle(Marker::APP13));
        self::assertSame(1, $hits);
    }

    /**
     * Verifies APP0 JFIF routing by signature.
     */
    #[Test]
    public function jfifHandlerFiltersByMarkerAndSignature(): void
    {
        $stream  = new Stream($this->createTempStream('JPEG'), 4);
        $hits    = 0;
        $handler = new JfifSegmentHandler(function (string $_payload, int $_offset) use (&$hits): void {
            ++$hits;
        });

        $handler->handle($stream, "JFIF\0payload", 5);
        $handler->handle($stream, "JFXX\0payload", 6);
        $handler->handle($stream, "Exif\0\0payload", 7);

        self::assertTrue($handler->canHandle(Marker::APP0));
        self::assertFalse($handler->canHandle(Marker::APP1));
        self::assertFalse($handler->canHandle(Marker::APP2));
        self::assertSame(1, $hits);
    }
}
