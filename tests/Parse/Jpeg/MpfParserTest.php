<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ByteReader;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\Traits\ReadsBinaryPrimitives;
use MagicSunday\ImageMeta\Core\Util\Unpack;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Parse\Jpeg\MpfParser;
use MagicSunday\ImageMeta\Value\Enum\MpImageDataFormat;
use MagicSunday\ImageMeta\Value\Enum\MpImageType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function count;
use function is_string;
use function pack;
use function str_repeat;
use function strlen;

/**
 * Exercises MPF parsing for multi-picture metadata inside JPEG APP2 segments.
 * It verifies tag decoding, entry parsing, and attribute interpretation across types.
 * The suite includes malformed tags and invalid counts to assert ParseError handling.
 * This keeps MPF extraction stable for both minimal and multi-entry documents.
 *
 * @internal
 */
#[CoversClass(MpfParser::class)]
#[UsesClass(ByteReader::class)]
#[UsesClass(MemoryBuffer::class)]
#[UsesTrait(ReadsBinaryPrimitives::class)]
#[UsesClass(Unpack::class)]
#[UsesClass(MpfAttributes::class)]
#[UsesClass(MpfDocument::class)]
#[UsesClass(MpfEntry::class)]
#[UsesClass(MpImageDataFormat::class)]
#[UsesClass(MpImageType::class)]
final class MpfParserTest extends TestCase
{
    private const int TAG_MPF_VERSION = 0xB000;

    private const int TAG_NUMBER_OF_IMAGES = 0xB001;

    private const int TAG_MP_ENTRY = 0xB002;

    private const int TAG_IMAGE_UID_LIST = 0xB003;

    private const int TAG_TOTAL_FRAMES = 0xB004;

    private const int TAG_INDIVIDUAL_IMAGE_NUMBER = 0xB101;

    private const int TYPE_ASCII = 2;

    private const int TYPE_LONG = 4;

    private const int TYPE_UNDEFINED = 7;

    private const int TYPE_BYTE = 1;

    /**
     * Parses a full MPF payload including image entries and attribute tags.
     * This verifies version, entry list, and derived attributes are populated correctly.
     */
    #[Test]
    public function parsesCompleteMpfPayloadWithAttributes(): void
    {
        $entriesData = $this->buildMpEntries([
            [0x83000044, 1_000, 2_048, 1, 0],
            [0x44000088, 2_000, 4_096, 0, 2],
        ]);

        $uidList      = str_repeat("\xAB", 33);
        $extraTagData = pack('C*', 1, 2, 3, 4, 5, 6);

        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_NUMBER_OF_IMAGES, self::TYPE_LONG, 1, 2],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, strlen($entriesData), $entriesData],
            [self::TAG_IMAGE_UID_LIST, self::TYPE_UNDEFINED, strlen($uidList), $uidList],
            [self::TAG_TOTAL_FRAMES, self::TYPE_LONG, 1, 3],
        ];

        $attributeEntries = [
            [self::TAG_INDIVIDUAL_IMAGE_NUMBER, self::TYPE_LONG, 1, 2],
            [0xB123, self::TYPE_BYTE, strlen($extraTagData), $extraTagData],
        ];

        $payload = $this->buildMpfPayload($indexEntries, $attributeEntries);

        $parser   = new MpfParser();
        $document = $parser->parse($payload);

        $expected = new MpfDocument(
            version: '0100',
            imageCount: 2,
            entries: [
                new MpfEntry(
                    0x83000044,
                    1_000,
                    2_048,
                    1,
                    0,
                    true,
                    false,
                    false,
                    MpImageType::BaselinePrimaryImage,
                    null,
                ),
                new MpfEntry(
                    0x44000088,
                    2_000,
                    4_096,
                    0,
                    2,
                    false,
                    true,
                    false,
                    MpImageType::OriginalPreservationImage,
                    MpImageDataFormat::Jpeg,
                ),
            ],
            attributes: new MpfAttributes(
                individualImageNumber: 2,
                additionalTags: [
                    0xB123 => [1, 2, 3, 4, 5, 6],
                ],
            ),
            imageUidList: str_repeat("\xAB", 33),
            totalFrames: 3,
        );

        self::assertEquals($expected, $document);
    }

    /**
     * Omits the NumberOfImages tag while keeping MP Entry data.
     * This confirms the parser derives imageCount from the entry list length.
     */
    #[Test]
    public function defaultsImageCountWhenTagMissing(): void
    {
        $entriesData = $this->buildMpEntries([
            [0x01020304, 512, 1_024, 0, 0],
            [0x02030405, 256, 2_048, 0, 1],
        ]);

        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, strlen($entriesData), $entriesData],
        ];

        $parser   = new MpfParser();
        $document = $parser->parse($this->buildMpfPayload($indexEntries));

        self::assertSame(2, $document->imageCount);
        self::assertCount(2, $document->entries);
    }

    /**
     * Uses an invalid MP Entry payload length.
     * This asserts the parser rejects malformed entry data with a ParseError.
     */
    #[Test]
    public function rejectsMpEntryDataWithInvalidLength(): void
    {
        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, 3, 'bad'],
        ];

        $parser = new MpfParser();

        $this->expectException(ParseError::class);
        $parser->parse($this->buildMpfPayload($indexEntries));
    }

    /**
     * Verifies bitfield decomposition for an entry with all flags set.
     */
    #[Test]
    public function decomposesRepresentativeParentEntry(): void
    {
        $entriesData = $this->buildMpEntries([
            [0xE3000000, 4_096, 0, 0, 0],
        ]);

        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_NUMBER_OF_IMAGES, self::TYPE_LONG, 1, 1],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, strlen($entriesData), $entriesData],
        ];

        $parser   = new MpfParser();
        $document = $parser->parse($this->buildMpfPayload($indexEntries));

        $entry = $document->entries[0];
        self::assertTrue($entry->isDependentParent);
        self::assertTrue($entry->isDependentChild);
        self::assertTrue($entry->isRepresentativeImage);
        self::assertSame(MpImageType::BaselinePrimaryImage, $entry->imageType);
        self::assertSame(MpImageDataFormat::Jpeg, $entry->imageDataFormat);
    }

    /**
     * Verifies all MPF 2025 type codes are parsed correctly from single-entry payloads.
     */
    #[Test]
    public function parsesOriginalPreservationImageType(): void
    {
        self::assertSame(
            MpImageType::OriginalPreservationImage,
            $this->parseSingleEntryType(0x04000000),
        );
    }

    #[Test]
    public function parsesGainMapImageType(): void
    {
        self::assertSame(
            MpImageType::GainMapImage,
            $this->parseSingleEntryType(0x05000000),
        );
    }

    #[Test]
    public function parsesLargeThumbnailQfhdType(): void
    {
        self::assertSame(
            MpImageType::LargeThumbnailQfhd,
            $this->parseSingleEntryType(0x01030000),
        );
    }

    #[Test]
    public function parsesLargeThumbnail8kType(): void
    {
        self::assertSame(
            MpImageType::LargeThumbnail8k,
            $this->parseSingleEntryType(0x01040000),
        );
    }

    #[Test]
    public function parsesLargeThumbnail16kType(): void
    {
        self::assertSame(
            MpImageType::LargeThumbnail16k,
            $this->parseSingleEntryType(0x01050000),
        );
    }

    private function parseSingleEntryType(int $attributes): ?MpImageType
    {
        $entriesData = $this->buildMpEntries([
            [$attributes, 1_024, 0, 0, 0],
        ]);

        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_NUMBER_OF_IMAGES, self::TYPE_LONG, 1, 1],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, strlen($entriesData), $entriesData],
        ];

        $parser   = new MpfParser();
        $document = $parser->parse($this->buildMpfPayload($indexEntries));

        return $document->entries[0]->imageType;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int,4:int}> $entries
     */
    private function buildMpEntries(array $entries): string
    {
        $binary = '';

        foreach ($entries as [$attributes, $size, $offset, $dependent1, $dependent2]) {
            $binary .= pack('V', $attributes)
                . pack('V', $size)
                . pack('V', $offset)
                . pack('v', $dependent1)
                . pack('v', $dependent2);
        }

        return $binary;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int|string}>      $entries
     * @param list<array{0:int,1:int,2:int,3:int|string}>|null $attributeEntries
     */
    private function buildMpfPayload(array $entries, ?array $attributeEntries = null): string
    {
        $header         = 'II' . pack('v', 42);
        $firstIfdOffset = 8;

        $indexIfd = $this->prepareIfd($entries, $firstIfdOffset);
        $indexLen = strlen($indexIfd['body']) + 4;

        $attributeOffset = 0;
        $attributeIfd    = null;

        if ($attributeEntries !== null) {
            $attributeOffset = $firstIfdOffset + $indexLen + strlen($indexIfd['data']);
            $attributeIfd    = $this->prepareIfd($attributeEntries, $attributeOffset);
        }

        $indexNextOffset = $attributeEntries !== null ? $attributeOffset : 0;
        $indexBinary     = $indexIfd['body'] . pack('V', $indexNextOffset);

        $payload = $header . pack('V', $firstIfdOffset) . $indexBinary . $indexIfd['data'];

        if ($attributeIfd !== null) {
            $payload .= $attributeIfd['body'] . pack('V', 0) . $attributeIfd['data'];
        }

        return $payload;
    }

    /**
     * @param list<array{0:int,1:int,2:int,3:int|string}> $entries
     *                                                             Builds an IFD body and associated data section for the provided entries.
     *                                                             String values are stored in the data block with offsets relative to the IFD.
     *
     * @return array{body:string,data:string}
     */
    private function prepareIfd(array $entries, int $ifdOffset): array
    {
        $count      = count($entries);
        $body       = pack('v', $count);
        $data       = '';
        $dataOffset = $ifdOffset + 2 + ($count * 12) + 4;

        foreach ($entries as [$tag, $type, $components, $value]) {
            if (is_string($value) && (strlen($value) > 4)) {
                $offset = $dataOffset + strlen($data);
                $body .= pack('v', $tag)
                    . pack('v', $type)
                    . pack('V', $components)
                    . pack('V', $offset);
                $data .= $value;

                continue;
            }

            if (is_string($value)) {
                $body .= pack('v', $tag)
                    . pack('v', $type)
                    . pack('V', $components)
                    . str_pad($value, 4, "\0");

                continue;
            }

            $body .= pack('v', $tag)
                . pack('v', $type)
                . pack('V', $components)
                . pack('V', $value);
        }

        return [
            'body' => $body,
            'data' => $data,
        ];
    }
}
