<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\DngValueNormalizer;
use MagicSunday\ImageMeta\Parse\Tiff\MakerNoteDispatcher;
use MagicSunday\ImageMeta\Parse\Tiff\TiffBinaryReader;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifTagValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffIfdTraverser;
use MagicSunday\ImageMeta\Parse\Tiff\TiffJpegThumbnailValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffOffsetValidator;
use MagicSunday\ImageMeta\Parse\Tiff\TiffValueDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;
use function ksort;
use function str_pad;
use function strlen;

/**
 * Verifies TIFF separated-image InkSet/InkNames/NumberOfInks semantics.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(DngValueNormalizer::class)]
#[UsesClass(MakerNoteDispatcher::class)]
#[UsesClass(TiffBinaryReader::class)]
#[UsesClass(TiffExifTagValidator::class)]
#[UsesClass(TiffIfdTraverser::class)]
#[UsesClass(TiffJpegThumbnailValidator::class)]
#[UsesClass(TiffOffsetValidator::class)]
#[UsesClass(TiffValueDecoder::class)]
final class TiffExifParserSeparatedImageInkTagsTest extends TestCase
{
    /**
     * CMYK InkSet=1 without InkNames is valid.
     */
    #[Test]
    public function acceptsCmykInkSetWithoutInkNames(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(inkSetType: TiffConst::TYPE_SHORT, inkSetCount: 1, inkSetValue: 1),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::PHOTOMETRIC_INTERPRETATION));
    }

    /**
     * TargetPrinter is valid for separated images.
     */
    #[Test]
    public function acceptsTargetPrinterForSeparatedPhotometricInterpretation(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 1,
                targetPrinterPayload: "ProofDevice\0",
            ),
        );

        self::assertSame('ProofDevice', $parsed->targetPrinter());
    }

    /**
     * TargetPrinter must be rejected in non-separated photometric contexts.
     */
    #[Test]
    public function rejectsTargetPrinterForNonSeparatedPhotometricInterpretation(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('TargetPrinter (tag 337) is only valid when PhotometricInterpretation=5 (Separated).');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 1,
                photometricInterpretation: 2,
                targetPrinterPayload: "ProofDevice\0",
            ),
        );
    }

    /**
     * Omitting TargetPrinter in non-separated contexts keeps existing behavior.
     */
    #[Test]
    public function acceptsMissingTargetPrinterForNonSeparatedPhotometricInterpretation(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 1,
                photometricInterpretation: 2,
            ),
        );

        self::assertNull($parsed->targetPrinter());
    }

    /**
     * InkNames is forbidden for InkSet=1.
     */
    #[Test]
    public function rejectsInkNamesWhenInkSetIsCmyk(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkNames must not be present when InkSet=1');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 1,
                numberOfInks: 2,
                inkNamesPayload: "Cyan\0Magenta\0",
            ),
        );
    }

    /**
     * InkSet=2 requires NUL-separated names; malformed single-string payload is rejected.
     */
    #[Test]
    public function rejectsMalformedInkNamesForInkSetTwo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkNames string count 1 must match NumberOfInks 2');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 2,
                numberOfInks: 2,
                inkNamesPayload: "CyanMagenta\0",
            ),
        );
    }

    /**
     * InkSet=2 requires InkNames count to match NumberOfInks.
     */
    #[Test]
    public function rejectsInkNamesCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('InkNames string count 2 must match NumberOfInks 3');

        (new TiffExifParser())->parseFromBlob(
            $this->buildSeparatedImageInkTiff(
                inkSetType: TiffConst::TYPE_SHORT,
                inkSetCount: 1,
                inkSetValue: 2,
                numberOfInks: 3,
                inkNamesPayload: "Cyan\0Magenta\0",
            ),
        );
    }

    /**
     * InkSet invalid domain/type/count combinations are rejected.
     */
    #[Test]
    public function rejectsInvalidInkSetLayouts(): void
    {
        $cases = [
            [TiffConst::TYPE_SHORT, 1, 3, null, null], // invalid domain value
            [TiffConst::TYPE_BYTE, 1, 1, null, null],  // invalid type
            [TiffConst::TYPE_SHORT, 2, 1, null, pack('v2', 1, 1)], // invalid count
        ];
        $rejections = 0;

        foreach ($cases as [$inkSetType, $inkSetCount, $inkSetValue, $inkNamesPayload, $inkSetRawPayload]) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildSeparatedImageInkTiff(
                        inkSetType: $inkSetType,
                        inkSetCount: $inkSetCount,
                        inkSetValue: $inkSetValue,
                        inkNamesPayload: $inkNamesPayload,
                        inkSetRawPayload: $inkSetRawPayload,
                    ),
                );
                self::fail('Expected ParseError for invalid InkSet semantics.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * Builds a minimal TIFF with separated-image tags in IFD0.
     */
    private function buildSeparatedImageInkTiff(
        int $inkSetType,
        int $inkSetCount,
        int $inkSetValue,
        ?int $numberOfInks = null,
        ?string $inkNamesPayload = null,
        ?string $inkSetRawPayload = null,
        int $photometricInterpretation = 5,
        ?string $targetPrinterPayload = null,
    ): string {
        $ifdOffset = 8;

        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::PHOTOMETRIC_INTERPRETATION => pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $photometricInterpretation) . pack('v', 0),
            TiffTag::INK_SET => pack('v', TiffTag::INK_SET)
                . pack('v', $inkSetType)
                . pack('V', $inkSetCount),
        ];

        $payloadByTag = [];

        if ($inkSetRawPayload !== null) {
            $payloadByTag[TiffTag::INK_SET] = $inkSetRawPayload;
        } elseif (($inkSetType === TiffConst::TYPE_SHORT) && ($inkSetCount === 1)) {
            $entries[TiffTag::INK_SET] .= pack('v', $inkSetValue) . pack('v', 0);
        } elseif (($inkSetType === TiffConst::TYPE_BYTE) && ($inkSetCount === 1)) {
            $entries[TiffTag::INK_SET] .= pack('C', $inkSetValue) . "\0\0\0";
        } else {
            $payloadByTag[TiffTag::INK_SET] = pack('v2', $inkSetValue, $inkSetValue);
        }

        if ($numberOfInks !== null) {
            $entries[TiffTag::NUMBER_OF_INKS] = pack('v', TiffTag::NUMBER_OF_INKS)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $numberOfInks) . pack('v', 0);
        }

        if ($inkNamesPayload !== null) {
            $entries[TiffTag::INK_NAMES] = pack('v', TiffTag::INK_NAMES)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($inkNamesPayload));
            $payloadByTag[TiffTag::INK_NAMES] = $inkNamesPayload;
        }

        if ($targetPrinterPayload !== null) {
            $entries[TiffTag::TARGET_PRINTER] = pack('v', TiffTag::TARGET_PRINTER)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($targetPrinterPayload));
            $payloadByTag[TiffTag::TARGET_PRINTER] = $targetPrinterPayload;
        }

        ksort($entries);

        $entryCount = count($entries);
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $nextOffset = $ifdOffset + $ifdSize;
        $ifdEntries = '';
        $tailData   = '';

        foreach ($entries as $tag => $prefix) {
            if (!isset($payloadByTag[$tag])) {
                $ifdEntries .= $prefix;

                continue;
            }

            $payload = $payloadByTag[$tag];

            if (strlen($payload) <= 4) {
                $ifdEntries .= $prefix . str_pad($payload, 4, "\0");

                continue;
            }

            $ifdEntries .= $prefix . pack('V', $nextOffset);
            $tailData .= $payload;
            $nextOffset += strlen($payload);
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $tailData;
    }
}
