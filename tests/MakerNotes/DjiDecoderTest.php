<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\Dji\DjiMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\DjiDecoder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function implode;
use function pack;
use function sha1;
use function strlen;

/**
 * Exercises DJI maker note decoding for vendor-specific fields.
 * It verifies gimbal angles, aircraft speeds and compass extraction.
 * The suite checks malformed payloads yield null vendor-specific data.
 * This keeps DJI maker note handling robust and predictable.
 */
#[CoversClass(DjiDecoder::class)]
#[UsesClass(DjiMakerNotes::class)]
#[UsesClass(MakerNotesRecord::class)]
final class DjiDecoderTest extends TestCase
{
    /**
     * Decodes a little-endian DJI maker note payload with gimbal angles and speeds.
     * Verifies the decoder populates DjiMakerNotes and normalizes the vendor name.
     */
    #[Test]
    public function decodeExtractsDjiFieldsFromLittleEndianMakerNote(): void
    {
        $raw     = $this->buildLittleEndianDjiMakerNote();
        $decoder = new DjiDecoder();
        $record  = $decoder->decode($raw, 'DJI', 'FC8671');

        self::assertSame('DJI', $record->vendor);
        self::assertInstanceOf(DjiMakerNotes::class, $record->dji);
        self::assertEqualsWithDelta(-1.5, $record->dji->speedX, 0.001);
        self::assertEqualsWithDelta(2.3, $record->dji->speedY, 0.001);
        self::assertEqualsWithDelta(0.1, $record->dji->speedZ, 0.001);
        self::assertEqualsWithDelta(-5.0, $record->dji->pitch, 0.001);
        self::assertEqualsWithDelta(120.5, $record->dji->yaw, 0.001);
        self::assertEqualsWithDelta(0.2, $record->dji->roll, 0.001);
        self::assertEqualsWithDelta(-10.0, $record->dji->cameraPitch, 0.001);
        self::assertEqualsWithDelta(120.5, $record->dji->cameraYaw, 0.001);
        self::assertEqualsWithDelta(0.0, $record->dji->cameraRoll, 0.001);
        self::assertEqualsWithDelta(275.3, $record->dji->compass, 0.001);
    }

    /**
     * Decodes a big-endian DJI maker note payload with gimbal angles and speeds.
     * Verifies the decoder populates DjiMakerNotes correctly with big-endian byte order.
     */
    #[Test]
    public function decodeExtractsDjiFieldsFromBigEndianMakerNote(): void
    {
        $raw     = $this->buildBigEndianDjiMakerNote();
        $decoder = new DjiDecoder();
        $record  = $decoder->decode($raw, 'DJI', 'FC8671');

        self::assertSame('DJI', $record->vendor);
        self::assertInstanceOf(DjiMakerNotes::class, $record->dji);
        self::assertEqualsWithDelta(-5.0, $record->dji->pitch, 0.001);
        self::assertEqualsWithDelta(275.3, $record->dji->compass, 0.001);
    }

    /**
     * Feeds an invalid maker note payload to the decoder.
     * Ensures the DJI notes are left null for malformed input.
     */
    #[Test]
    public function decodeReturnsNullDjiNotesForInvalidPayload(): void
    {
        $decoder = new DjiDecoder();
        $record  = $decoder->decode('x', 'DJI', null);

        self::assertNull($record->dji);
    }

    /**
     * Feeds a maker note with only vendor-specific calibration tags (> 0x000E).
     * Ensures no DjiMakerNotes are created since no known tags are present.
     */
    #[Test]
    public function decodeReturnsNullDjiNotesWhenOnlyCalibrationTagsPresent(): void
    {
        $raw     = $this->buildCalibrationOnlyMakerNote();
        $decoder = new DjiDecoder();
        $record  = $decoder->decode($raw, 'DJI', 'FC8671');

        self::assertNull($record->dji);
    }

    /**
     * Verifies the decoder produces correct record-level metadata.
     */
    #[Test]
    public function decodePopulatesRecordMetadata(): void
    {
        $raw     = $this->buildLittleEndianDjiMakerNote();
        $decoder = new DjiDecoder();
        $record  = $decoder->decode($raw, 'DJI', 'FC8671');

        self::assertSame('DJI', $record->vendor);
        self::assertSame(strlen($raw), $record->length);
        self::assertSame(sha1($raw), $record->sha1);
    }

    private function buildLittleEndianDjiMakerNote(): string
    {
        $entries = [];

        $entries[] = $this->buildEntry(0x0003, 11, 1, pack('g', -1.5));
        $entries[] = $this->buildEntry(0x0004, 11, 1, pack('g', 2.3));
        $entries[] = $this->buildEntry(0x0005, 11, 1, pack('g', 0.1));
        $entries[] = $this->buildEntry(0x0006, 11, 1, pack('g', -5.0));
        $entries[] = $this->buildEntry(0x0007, 11, 1, pack('g', 120.5));
        $entries[] = $this->buildEntry(0x0008, 11, 1, pack('g', 0.2));
        $entries[] = $this->buildEntry(0x0009, 11, 1, pack('g', -10.0));
        $entries[] = $this->buildEntry(0x000A, 11, 1, pack('g', 120.5));
        $entries[] = $this->buildEntry(0x000B, 11, 1, pack('g', 0.0));
        $entries[] = $this->buildEntry(0x000E, 11, 1, pack('g', 275.3));

        return pack('v', 10) . implode('', $entries) . pack('V', 0);
    }

    private function buildBigEndianDjiMakerNote(): string
    {
        $entries = [];

        $entries[] = $this->buildBigEndianEntry(0x0006, 11, 1, pack('G', -5.0));
        $entries[] = $this->buildBigEndianEntry(0x000E, 11, 1, pack('G', 275.3));

        return pack('n', 2) . implode('', $entries) . pack('N', 0);
    }

    private function buildCalibrationOnlyMakerNote(): string
    {
        $entries   = [];
        $entries[] = $this->buildEntry(0x1002, 7, 100, pack('V', 0x0560));

        return pack('v', 1) . implode('', $entries) . pack('V', 0);
    }

    private function buildEntry(int $tag, int $type, int $count, string $value): string
    {
        $padded = $value . "\0\0\0\0";

        return pack('v', $tag) . pack('v', $type) . pack('V', $count) . substr($padded, 0, 4);
    }

    private function buildBigEndianEntry(int $tag, int $type, int $count, string $value): string
    {
        $padded = $value . "\0\0\0\0";

        return pack('n', $tag) . pack('n', $type) . pack('N', $count) . substr($padded, 0, 4);
    }
}
