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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Dng\DngTag;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;
use MagicSunday\ImageMeta\Parse\Tiff\TiffConst;
use MagicSunday\ImageMeta\Parse\Tiff\TiffExifParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

/**
 * Verifies parsing and exposure of baseline DNG tags from TIFF/EXIF payloads.
 */
#[CoversClass(TiffExifParser::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(TiffConst::class)]
#[UsesClass(DngTag::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(TiffTag::class)]
#[UsesClass(MakerNotesRecord::class)]
final class TiffExifParserDngTagTest extends TestCase
{
    /**
     * Parses DNG core tags from IFD0 and exposes them through ParsedExif accessors.
     *
     * @return void
     */
    #[Test]
    public function parsesCoreDngTagsFromIfd0(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildClassicDngTiff());

        self::assertSame('1.7.1.0', $parsed->dngVersion());
        self::assertSame('1.4.0.0', $parsed->dngBackwardVersion());
        self::assertSame('MagicSunday Camera', $parsed->uniqueCameraModel());
    }

    /**
     * Returns null for DNG accessors when corresponding tags are not present.
     *
     * @return void
     */
    #[Test]
    public function returnsNullWhenCoreDngTagsAreMissing(): void
    {
        $parser = new TiffExifParser();
        // IFD0 with non-DNG tags (ImageWidth + ImageLength)
        $parsed = $parser->parseFromBlob(
            'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', 8)
            . pack('v', 2)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 1)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 1)
            . pack('V', 0),
        );

        self::assertNull($parsed->dngVersion());
        self::assertNull($parsed->dngBackwardVersion());
        self::assertNull($parsed->uniqueCameraModel());
    }

    /**
     * DNG 1.7.1.0: LocalizedCameraModel may use BYTE type instead of ASCII.
     * When stored as BYTE, the parser decodes the raw bytes as a NUL-terminated
     * UTF-8 string rather than a numeric list.
     *
     * @return void
     */
    #[Test]
    public function decodesLocalizedCameraModelByteAsUtf8String(): void
    {
        $model      = "Camera Model\0";
        $ifdOffset  = 8;
        $entryCount = 3;
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        $blob = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::LOCALIZED_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', strlen($model))
            . pack('V', $valOffset)
            . pack('V', 0)
            . $model;

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($blob);

        $entry = $parsed->ifd0->get(DngTag::LOCALIZED_CAMERA_MODEL);
        self::assertNotNull($entry);
        self::assertSame('Camera Model', $entry->value);
    }

    /**
     * Valid DNGPrivateData with NUL-terminated ASCII prefix parses successfully.
     */
    #[Test]
    public function parsesValidDngPrivateData(): void
    {
        $privateData = "Adobe\0\x01\x02\x03\x04";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($blob);

        $entry = $parsed->ifd0->get(DngTag::DNG_PRIVATE_DATA);
        self::assertNotNull($entry);
    }

    /**
     * DNGPrivateData without NUL terminator is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithoutNulTerminator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData block must start with a NUL-terminated ASCII string per DNG 1.7.1.0.');

        $privateData = 'AdobeNoNul';
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * DNGPrivateData with non-ASCII byte in prefix is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithNonAsciiPrefix(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData manufacturer name contains non-ASCII byte 0x80 at offset 5 per DNG 1.7.1.0.');

        $privateData = "Adobe\x80\0\x01\x02";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * DNGPrivateData with empty manufacturer name is rejected.
     */
    #[Test]
    public function rejectDngPrivateDataWithEmptyManufacturerName(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('DNGPrivateData manufacturer name must not be empty per DNG 1.7.1.0.');

        $privateData = "\0\x01\x02\x03\x04";
        $blob        = $this->buildTiffWithDngPrivateData($privateData);

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * MakerNoteSafety=1 is exposed as safe=true on the MakerNotesRecord.
     */
    #[Test]
    public function exposesMakerNoteSafetyTrue(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithMakerNoteSafety(1));

        self::assertNotNull($parsed->makerNotes());
        self::assertTrue($parsed->makerNotes()->safe);
    }

    /**
     * MakerNoteSafety=0 is exposed as safe=false on the MakerNotesRecord.
     */
    #[Test]
    public function exposesMakerNoteSafetyFalse(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithMakerNoteSafety(0));

        self::assertNotNull($parsed->makerNotes());
        self::assertFalse($parsed->makerNotes()->safe);
    }

    /**
     * MakerNoteSafety value outside {0, 1} triggers a ParseError.
     */
    #[Test]
    public function rejectInvalidMakerNoteSafetyDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('MakerNoteSafety value 2 is outside the valid domain {0, 1} per DNG 1.7.1.0.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithMakerNoteSafety(2));
    }

    /**
     * Builds a classic TIFF payload with required baseline DNG tags in IFD0.
     *
     * DNG 1.7.1.0 (DNG Tags, pp. 24-25) defines DNGVersion and
     * DNGBackwardVersion as BYTE[4], and UniqueCameraModel as ASCII.
     */
    private function buildClassicDngTiff(): string
    {
        $ifdOffset         = 8;
        $entryCount        = 5;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'MagicSunday Camera');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00010701)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('V', 0x00000401)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a classic TIFF payload with a single DNGPrivateData tag in IFD0.
     */
    private function buildTiffWithDngPrivateData(string $privateData): string
    {
        $ifdOffset  = 8;
        $entryCount = 3;
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::DNG_PRIVATE_DATA)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', strlen($privateData))
            . pack('V', $valOffset)
            . pack('V', 0)
            . $privateData;
    }

    /**
     * Builds a TIFF with IFD0 containing EXIF_IFD_POINTER + MakerNoteSafety,
     * and an EXIF IFD containing a minimal MakerNote.
     */
    /**
     * Enhanced IFD with valid EnhanceParams parses without error.
     */
    #[Test]
    public function parsesEnhancedIfdWithValidEnhanceParams(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTiffWithEnhancedIfd("Adobe Enhance\0"));

        $entry = $parsed->ifd0->get(DngTag::ENHANCE_PARAMS);
        self::assertNotNull($entry);
        self::assertSame('Adobe Enhance', $entry->value);
    }

    /**
     * Enhanced IFD without EnhanceParams triggers a ParseError.
     */
    #[Test]
    public function rejectEnhancedIfdMissingEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Enhanced IFD (NewSubfileType bit 4) requires an EnhanceParams tag per DNG 1.5.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithEnhancedIfd(null));
    }

    /**
     * Enhanced IFD with empty EnhanceParams triggers a ParseError.
     */
    #[Test]
    public function rejectEnhancedIfdWithEmptyEnhanceParams(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('EnhanceParams must not be empty for an Enhanced IFD per DNG 1.5.');

        (new TiffExifParser())->parseFromBlob($this->buildTiffWithEnhancedIfd("\0"));
    }

    /**
     * Builds a TIFF with IFD0 containing NewSubfileType=16 (enhanced) and
     * optionally an EnhanceParams tag.
     */
    private function buildTiffWithEnhancedIfd(?string $enhanceParams): string
    {
        $ifdOffset  = 8;
        $baseCount  = $enhanceParams !== null ? 2 : 1;
        $entryCount = $baseCount + 2; // + ImageWidth + ImageLength
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        // Tags sorted ascending: NEW_SUBFILE_TYPE(0xFE) < IMAGE_WIDTH(0x100) < IMAGE_LENGTH(0x101) < ENHANCE_PARAMS(0xC7EE)
        $ifdData = pack('v', $entryCount)
            // NewSubfileType: LONG, count=1, value=16 (enhanced) — fits inline
            . pack('v', TiffTag::NEW_SUBFILE_TYPE)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 16)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $outOfLine = '';

        if ($enhanceParams !== null) {
            $len = strlen($enhanceParams);

            $ifdData .= pack('v', DngTag::ENHANCE_PARAMS)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', $len);

            if ($len <= 4) {
                // Inline: pad value to 4 bytes
                $ifdData .= str_pad($enhanceParams, 4, "\0");
            } else {
                $ifdData .= pack('V', $valOffset);
                $outOfLine = $enhanceParams;
            }
        }

        $ifdData .= pack('V', 0); // next IFD offset

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $outOfLine;
    }

    /**
     * Valid ColorMatrix1 with count matching CfaPlaneColor-derived ColorPlanes parses
     * successfully per DNG 1.7.1.0.
     */
    #[Test]
    public function parsesValidColorMatrixWithCorrectCount(): void
    {
        $blob = $this->buildTiffWithDngMatrixTag(
            DngTag::COLOR_MATRIX_1,
            TiffConst::TYPE_SRATIONAL,
            9,
            3,
        );

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($blob);

        $entry = $parsed->ifd0->get(DngTag::COLOR_MATRIX_1);
        self::assertNotNull($entry);
    }

    /**
     * ColorMatrix1 with wrong element count triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsColorMatrixWithWrongCount(): void
    {
        $this->expectException(ParseError::class);

        $blob = $this->buildTiffWithDngMatrixTag(
            DngTag::COLOR_MATRIX_1,
            TiffConst::TYPE_SRATIONAL,
            10,
            3,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * CameraCalibration1 with wrong element count triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsCameraCalibrationWithWrongCount(): void
    {
        $this->expectException(ParseError::class);

        $blob = $this->buildTiffWithDngMatrixTag(
            DngTag::CAMERA_CALIBRATION_1,
            TiffConst::TYPE_SRATIONAL,
            10,
            3,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * ForwardMatrix1 with wrong element count triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsForwardMatrixWithWrongCount(): void
    {
        $this->expectException(ParseError::class);

        $blob = $this->buildTiffWithDngMatrixTag(
            DngTag::FORWARD_MATRIX_1,
            TiffConst::TYPE_SRATIONAL,
            10,
            3,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Wrong TIFF type for ColorMatrix1 triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsMatrixTagWithWrongType(): void
    {
        $this->expectException(ParseError::class);

        $blob = $this->buildTiffWithDngMatrixTag(
            DngTag::COLOR_MATRIX_1,
            TiffConst::TYPE_RATIONAL,
            9,
            3,
        );

        (new TiffExifParser())->parseFromBlob($blob);
    }

    /**
     * Non-monochrome DNG (CfaPlaneColor count > 1) with missing ColorMatrix1
     * triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsNonMonochromeDngWithoutColorMatrix1(): void
    {
        $this->expectException(ParseError::class);

        // CfaPlaneColor with 3 planes but no ColorMatrix1
        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPlaneColor(3, false),
        );
    }

    /**
     * Non-monochrome DNG with valid ColorMatrix1 passes per DNG 1.7.1.0.
     */
    #[Test]
    public function acceptsNonMonochromeDngWithColorMatrix1(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildTiffWithCfaPlaneColor(3, true),
        );

        $entry = $parsed->ifd0->get(DngTag::COLOR_MATRIX_1);
        self::assertNotNull($entry);
    }

    /**
     * Monochrome DNG (CfaPlaneColor count = 1) without ColorMatrix1 is accepted.
     */
    #[Test]
    public function acceptsMonochromeDngWithoutColorMatrix1(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildTiffWithCfaPlaneColor(1, false),
        );

        self::assertNull($parsed->ifd0->get(DngTag::COLOR_MATRIX_1));
    }

    /**
     * Builds a classic TIFF with CfaPlaneColor and optionally ColorMatrix1.
     *
     * @param int  $colorPlanes      Number of color planes for CfaPlaneColor.
     * @param bool $includeColorMat1 Whether to include a ColorMatrix1 tag.
     */
    private function buildTiffWithCfaPlaneColor(int $colorPlanes, bool $includeColorMat1): string
    {
        $ifdOffset  = 8;
        $entryCount = $includeColorMat1 ? 4 : 3;
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        $cfaValues = '';
        for ($i = 0; $i < $colorPlanes; ++$i) {
            $cfaValues .= pack('C', $i);
        }

        $cfaValues = str_pad($cfaValues, 4, "\0");

        $ifdData = pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::CFA_PLANE_COLOR)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', $colorPlanes)
            . $cfaValues;

        $sratData = '';

        if ($includeColorMat1) {
            $matCount = $colorPlanes * 3;

            for ($i = 0; $i < $matCount; ++$i) {
                $sratData .= pack('VV', 1, 1);
            }

            $ifdData .= pack('v', DngTag::COLOR_MATRIX_1)
                . pack('v', TiffConst::TYPE_SRATIONAL)
                . pack('V', $matCount)
                . pack('V', $valOffset);
        }

        $ifdData .= pack('V', 0); // next IFD

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $sratData;
    }

    /**
     * Builds a classic TIFF with CfaPlaneColor (establishing ColorPlanes) and a single
     * DNG matrix tag.
     *
     * @param int $matrixTag   DNG matrix tag constant.
     * @param int $type        TIFF field type for the matrix tag.
     * @param int $count       Element count for the matrix tag.
     * @param int $colorPlanes Number of color planes (determines CfaPlaneColor count).
     */
    private function buildTiffWithDngMatrixTag(
        int $matrixTag,
        int $type,
        int $count,
        int $colorPlanes,
    ): string {
        $ifdOffset  = 8;
        $entryCount = 4; // ImageWidth + ImageLength + CfaPlaneColor + matrix tag
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $valOffset  = $ifdOffset + $ifdSize;

        // CfaPlaneColor: BYTE[colorPlanes] — fits inline for colorPlanes ≤ 4
        $cfaValues = '';
        for ($i = 0; $i < $colorPlanes; ++$i) {
            $cfaValues .= pack('C', $i);
        }

        $cfaValues = str_pad($cfaValues, 4, "\0");

        // SRATIONAL data: each entry is 8 bytes (numerator + denominator)
        $sratData = '';
        for ($i = 0; $i < $count; ++$i) {
            $sratData .= pack('VV', 1, 1); // 1/1 for each element
        }

        // Tags must be in ascending order: IMAGE_WIDTH(0x100) < IMAGE_LENGTH(0x101)
        // < CFA_PLANE_COLOR(0xC616) < matrix tag (0xC621+)
        $ifdData = pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::CFA_PLANE_COLOR)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', $colorPlanes)
            . $cfaValues
            . pack('v', $matrixTag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $valOffset)
            . pack('V', 0); // next IFD

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $sratData;
    }

    /**
     * CalibrationIlluminant1=255 without IlluminantData1 triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsIlluminant1ValueOtherWithoutData(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_1,
                255,
                null,
            ),
        );
    }

    /**
     * CalibrationIlluminant2=255 without IlluminantData2 triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsIlluminant2ValueOtherWithoutData(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_2,
                255,
                null,
            ),
        );
    }

    /**
     * CalibrationIlluminant3=255 without IlluminantData3 triggers a ParseError per DNG 1.7.1.0.
     */
    #[Test]
    public function rejectsIlluminant3ValueOtherWithoutData(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_3,
                255,
                null,
            ),
        );
    }

    /**
     * Non-255 calibration illuminant does not require IlluminantData.
     */
    #[Test]
    public function acceptsNonOtherIlluminantWithoutData(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_1,
                17,
                null,
            ),
        );

        $entry = $parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_1);
        self::assertNotNull($entry);
        self::assertSame(17, $entry->value);
    }

    /**
     * CalibrationIlluminant1=255 with IlluminantData1 present passes validation.
     */
    #[Test]
    public function acceptsIlluminantValueOtherWithData(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_1,
                255,
                DngTag::ILLUMINANT_DATA_1,
            ),
        );

        $entry = $parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_1);
        self::assertNotNull($entry);
        self::assertSame(255, $entry->value);
    }

    /**
     * Builds a classic TIFF with a CalibrationIlluminant tag and optionally an
     * IlluminantData tag.
     *
     * @param int      $illuminantTag CalibrationIlluminant tag constant.
     * @param int      $illuminantVal Illuminant value (255 = Other).
     * @param int|null $dataTag       IlluminantData tag constant, or null to omit.
     */
    private function buildTiffWithCalibrationIlluminant(
        int $illuminantTag,
        int $illuminantVal,
        ?int $dataTag,
    ): string {
        $ifdOffset  = 8;
        $entryCount = $dataTag !== null ? 4 : 3;

        // Collect tags in ascending order
        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            $illuminantTag => pack('v', $illuminantTag)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $illuminantVal) . pack('v', 0),
        ];

        if ($dataTag !== null) {
            // IlluminantData: UNDEFINED, minimal 4-byte payload inline
            $tags[$dataTag] = pack('v', $dataTag)
                . pack('v', TiffConst::TYPE_UNDEFINED)
                . pack('V', 4)
                . pack('a4', "\x01\x02\x03\x04");
        }

        ksort($tags);

        $ifdData = pack('v', $entryCount);
        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0); // next IFD

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData;
    }

    /**
     * CalibrationIlluminant3 without CalibrationIlluminant1/2 triggers ParseError.
     */
    #[Test]
    public function rejectsTripleIlluminantWithoutIlluminant1And2(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTripleIlluminantTiff(
                includeIlluminant1: false,
                includeIlluminant2: false,
            ),
        );
    }

    /**
     * CalibrationIlluminant3 without ColorMatrix3 triggers ParseError.
     */
    #[Test]
    public function rejectsTripleIlluminantWithoutColorMatrix3(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTripleIlluminantTiff(includeColorMatrix3: false),
        );
    }

    /**
     * Partial ForwardMatrix set (1 and 2 but not 3) triggers ParseError.
     */
    #[Test]
    public function rejectsPartialForwardMatrixSet(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTripleIlluminantTiff(
                includeForwardMatrix1: true,
                includeForwardMatrix2: true,
                includeForwardMatrix3: false,
            ),
        );
    }

    /**
     * Partial ReductionMatrix set (1 and 2 but not 3) triggers ParseError.
     */
    #[Test]
    public function rejectsPartialReductionMatrixSet(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTripleIlluminantTiff(
                includeReductionMatrix1: true,
                includeReductionMatrix2: true,
                includeReductionMatrix3: false,
            ),
        );
    }

    /**
     * Non-distinct illuminant values triggers ParseError.
     */
    #[Test]
    public function rejectsNonDistinctIlluminantValues(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTripleIlluminantTiff(
                illuminant1Val: 17,
                illuminant2Val: 17,
                illuminant3Val: 21,
            ),
        );
    }

    /**
     * Fully conformant triple-illuminant set passes validation.
     */
    #[Test]
    public function acceptsConformantTripleIlluminantProfile(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob($this->buildTripleIlluminantTiff());

        self::assertNotNull($parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_3));
    }

    /**
     * Builds a TIFF with triple-illuminant DNG tags for structural validation tests.
     *
     * By default builds a fully conformant triple-illuminant profile with distinct
     * illuminant values.
     */
    private function buildTripleIlluminantTiff(
        bool $includeIlluminant1 = true,
        bool $includeIlluminant2 = true,
        bool $includeColorMatrix3 = true,
        bool $includeForwardMatrix1 = false,
        bool $includeForwardMatrix2 = false,
        bool $includeForwardMatrix3 = false,
        bool $includeReductionMatrix1 = false,
        bool $includeReductionMatrix2 = false,
        bool $includeReductionMatrix3 = false,
        int $illuminant1Val = 17,
        int $illuminant2Val = 21,
        int $illuminant3Val = 23,
    ): string {
        $ifdOffset   = 8;
        $colorPlanes = 3;
        $matCount    = $colorPlanes * 3;

        // Tags keyed by tag ID for automatic ascending sort
        $tags          = [];
        $outOfLineData = '';

        // ImageWidth + ImageLength (always present)
        $tags[ExifTag::IMAGE_WIDTH] = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);
        $tags[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // CfaPlaneColor (3 planes)
        $tags[DngTag::CFA_PLANE_COLOR] = pack('v', DngTag::CFA_PLANE_COLOR)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', $colorPlanes)
            . "\x00\x01\x02\x00";

        // ColorMatrix1 (always present for non-monochrome)
        $tags[DngTag::COLOR_MATRIX_1] = 'PLACEHOLDER'; // will be replaced below

        if ($includeIlluminant1) {
            $tags[DngTag::CALIBRATION_ILLUMINANT_1] = pack('v', DngTag::CALIBRATION_ILLUMINANT_1)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $illuminant1Val) . pack('v', 0);
        }

        if ($includeIlluminant2) {
            $tags[DngTag::CALIBRATION_ILLUMINANT_2] = pack('v', DngTag::CALIBRATION_ILLUMINANT_2)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $illuminant2Val) . pack('v', 0);
        }

        // CalibrationIlluminant3 is always present in these tests
        $tags[DngTag::CALIBRATION_ILLUMINANT_3] = pack('v', DngTag::CALIBRATION_ILLUMINANT_3)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $illuminant3Val) . pack('v', 0);

        if ($includeColorMatrix3) {
            $tags[DngTag::COLOR_MATRIX_3] = 'PLACEHOLDER';
        }

        // Optional ForwardMatrix/ReductionMatrix tags
        $optionalMatrixTags = [];

        if ($includeForwardMatrix1) {
            $optionalMatrixTags[] = DngTag::FORWARD_MATRIX_1;
        }

        if ($includeForwardMatrix2) {
            $optionalMatrixTags[] = DngTag::FORWARD_MATRIX_2;
        }

        if ($includeForwardMatrix3) {
            $optionalMatrixTags[] = DngTag::FORWARD_MATRIX_3;
        }

        if ($includeReductionMatrix1) {
            $optionalMatrixTags[] = DngTag::REDUCTION_MATRIX_1;
        }

        if ($includeReductionMatrix2) {
            $optionalMatrixTags[] = DngTag::REDUCTION_MATRIX_2;
        }

        if ($includeReductionMatrix3) {
            $optionalMatrixTags[] = DngTag::REDUCTION_MATRIX_3;
        }

        foreach ($optionalMatrixTags as $tag) {
            $tags[$tag] = 'PLACEHOLDER';
        }

        ksort($tags);

        // Calculate IFD size to determine out-of-line data offsets
        $entryCount = count($tags);
        $ifdSize    = 2 + (12 * $entryCount) + 4;
        $curOffset  = $ifdOffset + $ifdSize;

        // Build SRATIONAL data block (all matrices use same dummy data: 1/1)
        $sratBlock = '';
        for ($i = 0; $i < $matCount; ++$i) {
            $sratBlock .= pack('VV', 1, 1);
        }

        // Replace placeholders with actual matrix entries pointing to out-of-line data
        foreach ($tags as $tag => &$data) {
            if ($data !== 'PLACEHOLDER') {
                continue;
            }

            $data = pack('v', $tag)
                . pack('v', TiffConst::TYPE_SRATIONAL)
                . pack('V', $matCount)
                . pack('V', $curOffset);
            $outOfLineData .= $sratBlock;
            $curOffset += strlen($sratBlock);
        }

        unset($data);

        $ifdData = pack('v', $entryCount);
        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0); // next IFD

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $outOfLineData;
    }

    /**
     * Only AsShotNeutral present parses successfully.
     */
    #[Test]
    public function acceptsOnlyAsShotNeutral(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceTags(true, false),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::AS_SHOT_NEUTRAL));
    }

    /**
     * Only AsShotWhiteXY present parses successfully.
     */
    #[Test]
    public function acceptsOnlyAsShotWhiteXY(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceTags(false, true),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::AS_SHOT_WHITE_XY));
    }

    /**
     * Both AsShotNeutral and AsShotWhiteXY present triggers ParseError.
     */
    #[Test]
    public function rejectsBothAsShotNeutralAndWhiteXY(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceTags(true, true),
        );
    }

    /**
     * Neither AsShotNeutral nor AsShotWhiteXY present is valid.
     */
    #[Test]
    public function acceptsNeitherAsShotTag(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceTags(false, false),
        );

        self::assertNull($parsed->ifd0->get(DngTag::AS_SHOT_NEUTRAL));
        self::assertNull($parsed->ifd0->get(DngTag::AS_SHOT_WHITE_XY));
    }

    /**
     * Builds a TIFF with optional AsShotNeutral and/or AsShotWhiteXY tags.
     */
    private function buildTiffWithWhiteBalanceTags(
        bool $includeNeutral,
        bool $includeWhiteXY,
    ): string {
        $ifdOffset = 8;
        $tags      = [];

        $tags[ExifTag::IMAGE_WIDTH] = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);
        $tags[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        $outOfLine  = '';
        $entryCount = 2;

        if ($includeNeutral) {
            // AsShotNeutral: RATIONAL[3] — 3 × 8 = 24 bytes, out-of-line
            $tags[DngTag::AS_SHOT_NEUTRAL] = 'PLACEHOLDER';
            ++$entryCount;
        }

        if ($includeWhiteXY) {
            // AsShotWhiteXY: RATIONAL[2] — 2 × 8 = 16 bytes, out-of-line
            $tags[DngTag::AS_SHOT_WHITE_XY] = 'PLACEHOLDER_XY';
            ++$entryCount;
        }

        ksort($tags);

        $ifdSize   = 2 + (12 * $entryCount) + 4;
        $curOffset = $ifdOffset + $ifdSize;

        // Replace placeholders
        foreach ($tags as $tag => &$data) {
            if ($data === 'PLACEHOLDER') {
                $ratData = pack('VV', 1, 3) . pack('VV', 1, 3) . pack('VV', 1, 3);
                $data    = pack('v', $tag)
                    . pack('v', TiffConst::TYPE_RATIONAL)
                    . pack('V', 3)
                    . pack('V', $curOffset);
                $outOfLine .= $ratData;
                $curOffset += strlen($ratData);
            } elseif ($data === 'PLACEHOLDER_XY') {
                $ratData = pack('VV', 3127, 10000) . pack('VV', 3290, 10000);
                $data    = pack('v', $tag)
                    . pack('v', TiffConst::TYPE_RATIONAL)
                    . pack('V', 2)
                    . pack('V', $curOffset);
                $outOfLine .= $ratData;
                $curOffset += strlen($ratData);
            }
        }

        unset($data);

        $ifdData = pack('v', $entryCount);
        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $outOfLine;
    }

    private function buildTiffWithMakerNoteSafety(int $safetyValue): string
    {
        // Layout: header(8) + IFD0(2 + 4*12 + 4 = 54) + EXIF IFD(2 + 12 + 4 = 18)
        $ifd0Offset     = 8;
        $ifd0EntryCount = 4;
        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4;
        $exifIfdOffset  = $ifd0Offset + $ifd0Size;

        $ifd0 = pack('v', $ifd0EntryCount)
            // ImageWidth SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ImageLength SHORT[1] = 100
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            // ExifIfdPointer: LONG, count=1, value=offset to EXIF IFD
            . pack('v', ExifTag::EXIF_IFD_POINTER)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $exifIfdOffset)
            // MakerNoteSafety: SHORT, count=1, value inline
            . pack('v', DngTag::MAKER_NOTE_SAFETY)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $safetyValue) . pack('v', 0)
            // next IFD offset
            . pack('V', 0);

        // EXIF IFD: MakerNote with 4 bytes inline (UNDEFINED type)
        $exifIfd = pack('v', 1)
            . pack('v', ExifTag::MAKER_NOTE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', 4)
            . pack('a4', 'TEST')
            . pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifd0Offset)
            . $ifd0
            . $exifIfd;
    }
}
