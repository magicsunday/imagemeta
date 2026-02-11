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
final class MpfParserTest extends TestCase
{
    private const int TAG_MPF_VERSION = 0xB000;

    private const int TAG_NUMBER_OF_IMAGES = 0xB001;

    private const int TAG_MP_ENTRY = 0xB002;

    private const int TAG_IMAGE_UID_LIST = 0xB003;

    private const int TAG_TOTAL_FRAMES = 0xB004;

    private const int TAG_INDIVIDUAL_IMAGE_NUMBER = 0xB005;

    private const int TAG_PANORAMA_ANGLE = 0xB006;

    private const int TAG_PANORAMA_AXIS = 0xB007;

    private const int TYPE_ASCII = 2;

    private const int TYPE_LONG = 4;

    private const int TYPE_RATIONAL = 5;

    private const int TYPE_UNDEFINED = 7;

    private const int TYPE_BYTE = 1;

    /**
     * Parses a full MPF payload including image entries and attribute tags.
     * This verifies version, entry list, and derived attributes are populated correctly.
     *
     * @return void
     */
    #[Test]
    public function parsesCompleteMpfPayloadWithAttributes(): void
    {
        $entriesData = $this->buildMpEntries([
            [0x83000044, 1_000, 2_048, 1, 0],
            [0x44000088, 2_000, 4_096, 0, 2],
        ]);

        $indexEntries = [
            [self::TAG_MPF_VERSION, self::TYPE_ASCII, 4, '0100'],
            [self::TAG_NUMBER_OF_IMAGES, self::TYPE_LONG, 1, 2],
            [self::TAG_MP_ENTRY, self::TYPE_UNDEFINED, strlen($entriesData), $entriesData],
        ];

        $panoramaAngleData = pack('V', 1) . pack('V', 2);
        $panoramaAxisData  = pack('V', 90) . pack('V', 1) . pack('V', 45) . pack('V', 1) . pack('V', 0) . pack('V', 1);
        $uidList           = str_repeat("\xAB", 33);
        $extraTagData      = pack('C*', 1, 2, 3, 4, 5, 6);

        $attributeEntries = [
            [self::TAG_IMAGE_UID_LIST, self::TYPE_UNDEFINED, strlen($uidList), $uidList],
            [self::TAG_TOTAL_FRAMES, self::TYPE_LONG, 1, 3],
            [self::TAG_INDIVIDUAL_IMAGE_NUMBER, self::TYPE_LONG, 1, 2],
            [self::TAG_PANORAMA_ANGLE, self::TYPE_RATIONAL, 1, $panoramaAngleData],
            [self::TAG_PANORAMA_AXIS, self::TYPE_RATIONAL, 3, $panoramaAxisData],
            [0xB123, self::TYPE_BYTE, strlen($extraTagData), $extraTagData],
        ];

        $payload = $this->buildMpfPayload($indexEntries, $attributeEntries);

        $parser   = new MpfParser();
        $document = $parser->parse($payload);

        $expected = new MpfDocument(
            version: '0100',
            imageCount: 2,
            entries: [
                new MpfEntry(0x83000044, 1_000, 2_048, 1, 0),
                new MpfEntry(0x44000088, 2_000, 4_096, 0, 2),
            ],
            attributes: new MpfAttributes(
                imageUidList: $uidList,
                totalFrames: 3,
                individualImageNumber: 2,
                panoramaAngle: [
                    ['numerator' => 1, 'denominator' => 2],
                ],
                panoramaAxis: [
                    ['numerator' => 90, 'denominator' => 1],
                    ['numerator' => 45, 'denominator' => 1],
                    ['numerator' => 0, 'denominator' => 1],
                ],
                additionalTags: [
                    0xB123 => [1, 2, 3, 4, 5, 6],
                ],
            ),
        );

        self::assertEquals($expected, $document);
    }

    /**
     * Omits the NumberOfImages tag while keeping MP Entry data.
     * This confirms the parser derives imageCount from the entry list length.
     *
     * @return void
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
     *
     * @return void
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
            if (is_string($value) && strlen($value) > 4) {
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
