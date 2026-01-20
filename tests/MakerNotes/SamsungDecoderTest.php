<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\Samsung\SamsungMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\SamsungDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function implode;
use function pack;
use function strlen;

/**
 * Validates decoding Samsung maker note payloads.
 */
#[CoversClass(SamsungDecoder::class)]
#[UsesClass(SamsungMakerNotes::class)]
final class SamsungDecoderTest extends TestCase
{
    #[Test]
    public function decodeExtractsSamsungFieldsFromMakerNote(): void
    {
        $raw = 'SAMSUNG' . "\0" . $this->buildSamsungMakerNote();

        $decoder = new SamsungDecoder();
        $record = $decoder->decode($raw, 'SAMSUNG', 'Galaxy S24');

        self::assertSame('Samsung', $record->vendor);
        self::assertInstanceOf(SamsungMakerNotes::class, $record->samsung);
        self::assertSame('0100', $record->samsung->makerNoteVersion);
        self::assertSame('Phone', $record->samsung->deviceType);
        self::assertSame(0x1234, $record->samsung->modelId);
    }

    #[Test]
    public function decodeReturnsNullSamsungNotesForInvalidPayload(): void
    {
        $decoder = new SamsungDecoder();
        $record = $decoder->decode('invalid', 'SAMSUNG', null);

        self::assertNull($record->samsung);
    }

    private function buildSamsungMakerNote(): string
    {
        $entries = [];
        $data = '';

        $version = "0100\0";
        $deviceType = "Phone\0";

        $versionOffset = 8 + 2 + (3 * 12) + 4;
        $deviceOffset = $versionOffset + strlen($version);

        $entries[] = $this->buildEntry(0x0001, 2, strlen($version), $versionOffset);
        $entries[] = $this->buildEntry(0x0002, 2, strlen($deviceType), $deviceOffset);
        $entries[] = $this->buildEntry(0x0003, 3, 1, 0x1234, true);

        $data .= $version;
        $data .= $deviceType;

        $ifd = pack('v', 3) . implode('', $entries) . pack('V', 0);

        return 'II' . pack('v', 0x2A) . pack('V', 8) . $ifd . $data;
    }

    private function buildEntry(int $tag, int $type, int $count, int $value, bool $inline = false): string
    {
        $valueBytes = $inline ? pack('v', $value) . "\0\0" : pack('V', $value);

        return pack('v', $tag) . pack('v', $type) . pack('V', $count) . $valueBytes;
    }
}
