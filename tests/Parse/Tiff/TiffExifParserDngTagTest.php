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
        $entryCount        = 6;
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
            // Orientation SHORT[1] = 1
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
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

    /**
     * RowInterleaveFactor=2 with DNGBackwardVersion < 1.2.0.0 triggers ParseError.
     */
    #[Test]
    public function rejectsRowInterleaveWithOldBackwardVersion(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithInterleaveFactor(
                DngTag::ROW_INTERLEAVE_FACTOR,
                2,
                [1, 1, 0, 0],
            ),
        );
    }

    /**
     * RowInterleaveFactor=1 with DNGBackwardVersion < 1.2.0.0 is valid (default value).
     */
    #[Test]
    public function acceptsDefaultRowInterleaveWithOldBackwardVersion(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithInterleaveFactor(
                DngTag::ROW_INTERLEAVE_FACTOR,
                1,
                [1, 1, 0, 0],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::ROW_INTERLEAVE_FACTOR));
    }

    /**
     * ColumnInterleaveFactor=2 with DNGBackwardVersion < 1.7.1.0 triggers ParseError.
     */
    #[Test]
    public function rejectsColumnInterleaveWithOldBackwardVersion(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithInterleaveFactor(
                DngTag::COLUMN_INTERLEAVE_FACTOR,
                2,
                [1, 7, 0, 0],
            ),
        );
    }

    /**
     * ColumnInterleaveFactor=1 with DNGBackwardVersion < 1.7.1.0 is valid (default value).
     */
    #[Test]
    public function acceptsDefaultColumnInterleaveWithOldBackwardVersion(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithInterleaveFactor(
                DngTag::COLUMN_INTERLEAVE_FACTOR,
                1,
                [1, 7, 0, 0],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::COLUMN_INTERLEAVE_FACTOR));
    }

    /**
     * RowInterleaveFactor=2 at minimum required version 1.2.0.0 is valid.
     */
    #[Test]
    public function acceptsRowInterleaveAtMinimumVersion(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithInterleaveFactor(
                DngTag::ROW_INTERLEAVE_FACTOR,
                2,
                [1, 2, 0, 0],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::ROW_INTERLEAVE_FACTOR));
    }

    /**
     * Builds a TIFF with a DNG interleave factor tag and DNGBackwardVersion.
     *
     * @param int       $interleaveTag Interleave factor tag constant.
     * @param int       $factorValue   Interleave factor value.
     * @param list<int> $backwardVer   DNGBackwardVersion bytes [major, minor, patch, sub].
     */
    private function buildTiffWithInterleaveFactor(
        int $interleaveTag,
        int $factorValue,
        array $backwardVer,
    ): string {
        $ifdOffset  = 8;
        $entryCount = 4; // ImageWidth + ImageLength + DNGBackwardVersion + interleave tag

        $tags = [];

        $tags[ExifTag::IMAGE_WIDTH] = pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);
        $tags[ExifTag::IMAGE_LENGTH] = pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0);

        // DNGBackwardVersion: BYTE[4], inline
        $verBytes                           = pack('C4', $backwardVer[0], $backwardVer[1], $backwardVer[2], $backwardVer[3]);
        $tags[DngTag::DNG_BACKWARD_VERSION] = pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . $verBytes;

        // Interleave factor: SHORT[1], inline
        $tags[$interleaveTag] = pack('v', $interleaveTag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $factorValue) . pack('v', 0);

        ksort($tags);

        $ifdData = pack('v', $entryCount);
        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData;
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

    /**
     * Both CalibrationIlluminant1 and CalibrationIlluminant2 present with one value 0 triggers ParseError.
     */
    #[Test]
    public function rejectsPairedCalibrationIlluminantWithZeroValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1479);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithPairedIlluminants(0, 17),
        );
    }

    /**
     * Both CalibrationIlluminant1 and CalibrationIlluminant2 present with non-zero values is valid.
     */
    #[Test]
    public function acceptsPairedCalibrationIlluminantsWithNonZeroValues(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithPairedIlluminants(17, 21),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_1));
        self::assertNotNull($parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_2));
    }

    /**
     * Only CalibrationIlluminant1 present with value 0 does not trigger the pair rule.
     */
    #[Test]
    public function acceptsSingleCalibrationIlluminantWithZeroValue(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCalibrationIlluminant(
                DngTag::CALIBRATION_ILLUMINANT_1,
                0,
                null,
            ),
        );

        self::assertNull($parsed->ifd0->get(DngTag::CALIBRATION_ILLUMINANT_2));
    }

    /**
     * Builds a minimal TIFF with both CalibrationIlluminant1 and CalibrationIlluminant2.
     */
    private function buildTiffWithPairedIlluminants(
        int $illuminant1Val,
        int $illuminant2Val,
    ): string {
        $ifdOffset  = 8;
        $entryCount = 4;

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            DngTag::CALIBRATION_ILLUMINANT_1 => pack('v', DngTag::CALIBRATION_ILLUMINANT_1)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $illuminant1Val) . pack('v', 0),
            DngTag::CALIBRATION_ILLUMINANT_2 => pack('v', DngTag::CALIBRATION_ILLUMINANT_2)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $illuminant2Val) . pack('v', 0),
        ];

        ksort($tags);

        $ifdData = pack('v', $entryCount);

        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData;
    }

    /**
     * ProfileToneCurve with odd FLOAT count triggers ParseError.
     */
    #[Test]
    public function rejectsProfileToneCurveWithOddCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1480);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithProfileToneCurve([0.0, 0.0, 0.5]),
        );
    }

    /**
     * ProfileToneCurve with non-increasing x values triggers ParseError.
     */
    #[Test]
    public function rejectsProfileToneCurveWithNonIncreasingX(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1481);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithProfileToneCurve([0.0, 0.0, 0.5, 0.5, 0.3, 0.8, 1.0, 1.0]),
        );
    }

    /**
     * ProfileToneCurve with out-of-range value triggers ParseError.
     */
    #[Test]
    public function rejectsProfileToneCurveWithOutOfRangeValue(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1482);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithProfileToneCurve([0.0, 0.0, 0.5, 1.5, 1.0, 1.0]),
        );
    }

    /**
     * Valid ProfileToneCurve with strictly increasing x parses successfully.
     */
    #[Test]
    public function acceptsValidProfileToneCurve(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithProfileToneCurve([0.0, 0.0, 0.5, 0.5, 1.0, 1.0]),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::PROFILE_TONE_CURVE));
    }

    /**
     * SDR ProfileToneCurve without required endpoints triggers ParseError.
     */
    #[Test]
    public function rejectsSdrProfileToneCurveWithWrongEndpoints(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1483);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithProfileToneCurve([0.1, 0.0, 0.5, 0.5, 1.0, 1.0]),
        );
    }

    /**
     * Builds a minimal TIFF with a ProfileToneCurve tag containing FLOAT values.
     *
     * @param list<float> $floats Flat list of alternating x,y values
     * @param bool        $hdr    Whether to include ProfileDynamicRange indicating HDR
     */
    private function buildTiffWithProfileToneCurve(
        array $floats,
        bool $hdr = false,
    ): string {
        $ifdOffset  = 8;
        $entryCount = $hdr ? 4 : 3;

        // Float data goes out-of-line after the IFD
        // IFD: 2 (count) + entryCount*12 (entries) + 4 (next IFD)
        $ifdSize      = 2 + ($entryCount * 12) + 4;
        $floatOffset  = $ifdOffset + $ifdSize;
        $floatPayload = '';

        foreach ($floats as $f) {
            $floatPayload .= pack('g', $f);
        }

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            DngTag::PROFILE_TONE_CURVE => pack('v', DngTag::PROFILE_TONE_CURVE)
                . pack('v', TiffConst::TYPE_FLOAT)
                . pack('V', count($floats))
                . pack('V', $floatOffset),
        ];

        $dynRangePayload = '';

        if ($hdr) {
            // ProfileDynamicRange: UNDEFINED, 8 bytes out-of-line
            $dynRangeOffset  = $floatOffset + strlen($floatPayload);
            $dynRangePayload = pack('v', 1)   // version = 1
                . pack('v', 1)                // dynamicRange = 1 (HDR)
                . pack('g', 1.0);             // hintMaxOutputValue

            $tags[DngTag::PROFILE_DYNAMIC_RANGE] = pack('v', DngTag::PROFILE_DYNAMIC_RANGE)
                . pack('v', TiffConst::TYPE_UNDEFINED)
                . pack('V', 8)
                . pack('V', $dynRangeOffset);
        }

        ksort($tags);

        $ifdData = pack('v', $entryCount);

        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $floatPayload
            . $dynRangePayload;
    }

    /**
     * DNG without Orientation triggers ParseError.
     */
    #[Test]
    public function rejectsDngWithoutOrientation(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1484);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithDngOrientation(isDng: true, includeOrientation: false),
        );
    }

    /**
     * DNG with Orientation present is valid.
     */
    #[Test]
    public function acceptsDngWithOrientation(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithDngOrientation(isDng: true, includeOrientation: true),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::ORIENTATION));
    }

    /**
     * Non-DNG TIFF without Orientation does not trigger the DNG rule.
     */
    #[Test]
    public function acceptsNonDngTiffWithoutOrientation(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithDngOrientation(isDng: false, includeOrientation: false),
        );

        self::assertNull($parsed->ifd0->get(ExifTag::ORIENTATION));
    }

    /**
     * Builds a minimal TIFF optionally marked as DNG and optionally containing Orientation.
     */
    private function buildTiffWithDngOrientation(
        bool $isDng,
        bool $includeOrientation,
    ): string {
        $ifdOffset = 8;

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
        ];

        if ($includeOrientation) {
            $tags[ExifTag::ORIENTATION] = pack('v', ExifTag::ORIENTATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 1) . pack('v', 0);
        }

        if ($isDng) {
            // DNGVersion: BYTE[4] inline
            $tags[DngTag::DNG_VERSION] = pack('v', DngTag::DNG_VERSION)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', 4)
                . pack('C4', 1, 7, 1, 0);
        }

        ksort($tags);

        $ifdData = pack('v', count($tags));

        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData;
    }

    /**
     * Depth map IFD with wrong PhotometricInterpretation triggers ParseError.
     */
    #[Test]
    public function rejectsDepthMapIfdWithWrongPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1485);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithRoleIfd(8, 2),
        );
    }

    /**
     * Semantic mask IFD with wrong PhotometricInterpretation triggers ParseError.
     */
    #[Test]
    public function rejectsSemanticMaskIfdWithWrongPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1485);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithRoleIfd(65540, 2),
        );
    }

    /**
     * Depth map IFD with correct PhotometricInterpretation (51177) is valid.
     */
    #[Test]
    public function acceptsDepthMapIfdWithCorrectPhotometric(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithRoleIfd(8, 51177),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Semantic mask IFD with correct PhotometricInterpretation (52527) is valid.
     */
    #[Test]
    public function acceptsSemanticMaskIfdWithCorrectPhotometric(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithRoleIfd(65540, 52527),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Regular IFD with non-depth/non-semantic NewSubFileType is not affected.
     */
    #[Test]
    public function acceptsRegularIfdWithAnyPhotometric(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithRoleIfd(1, 2),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a TIFF with IFD0 (minimal) chained to a second IFD with
     * the given NewSubFileType and PhotometricInterpretation.
     */
    private function buildTiffWithRoleIfd(
        int $newSubFileType,
        int $photometric,
    ): string {
        $ifdOffset   = 8;
        $ifd0Entries = 2;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;
        $ifd1Offset  = $ifdOffset + $ifd0Size;
        $ifd1Entries = 4;

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd1Offset);

        $tags = [
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
                . pack('v', $photometric) . pack('v', 0),
            TiffTag::NEW_SUBFILE_TYPE => pack('v', TiffTag::NEW_SUBFILE_TYPE)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $newSubFileType),
        ];

        ksort($tags);

        $ifd1 = pack('v', $ifd1Entries);

        foreach ($tags as $entry) {
            $ifd1 .= $entry;
        }

        $ifd1 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1;
    }

    /**
     * Valid AsShotNeutral with RATIONAL type and count matching ColorPlanes parses successfully.
     */
    #[Test]
    public function acceptsValidAsShotNeutralLayout(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_NEUTRAL,
                TiffConst::TYPE_RATIONAL,
                3,
                3,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::AS_SHOT_NEUTRAL));
    }

    /**
     * AsShotNeutral with wrong count triggers ParseError.
     */
    #[Test]
    public function rejectsAsShotNeutralWithWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1486);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_NEUTRAL,
                TiffConst::TYPE_RATIONAL,
                2,
                3,
            ),
        );
    }

    /**
     * AsShotNeutral with wrong type triggers ParseError.
     */
    #[Test]
    public function rejectsAsShotNeutralWithWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1486);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_NEUTRAL,
                TiffConst::TYPE_BYTE,
                3,
                3,
            ),
        );
    }

    /**
     * Valid AsShotWhiteXY with RATIONAL[2] parses successfully.
     */
    #[Test]
    public function acceptsValidAsShotWhiteXYLayout(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_WHITE_XY,
                TiffConst::TYPE_RATIONAL,
                2,
                3,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::AS_SHOT_WHITE_XY));
    }

    /**
     * AsShotWhiteXY with wrong count triggers ParseError.
     */
    #[Test]
    public function rejectsAsShotWhiteXYWithWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1487);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_WHITE_XY,
                TiffConst::TYPE_RATIONAL,
                3,
                3,
            ),
        );
    }

    /**
     * AsShotWhiteXY with wrong type triggers ParseError.
     */
    #[Test]
    public function rejectsAsShotWhiteXYWithWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1487);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::AS_SHOT_WHITE_XY,
                TiffConst::TYPE_BYTE,
                2,
                3,
            ),
        );
    }

    /**
     * Builds a TIFF with CfaPlaneColor, ColorMatrix1 and a white-balance tag
     * with given type/count. ColorMatrix1 is included to satisfy the matrix
     * validation for non-monochrome DNG.
     */
    private function buildTiffWithWhiteBalanceLayout(
        int $wbTag,
        int $wbType,
        int $wbCount,
        int $colorPlanes,
    ): string {
        $ifdOffset = 8;

        // CfaPlaneColor: BYTE, count = colorPlanes, inline (padded to 4 bytes)
        $cfaValues = '';

        for ($i = 0; $i < $colorPlanes; ++$i) {
            $cfaValues .= pack('C', $i);
        }

        $cfaValues = str_pad($cfaValues, 4, "\x00");

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            DngTag::CFA_PLANE_COLOR => pack('v', DngTag::CFA_PLANE_COLOR)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', $colorPlanes)
                . $cfaValues,
        ];

        // Placeholders for out-of-line tags
        $tags[DngTag::COLOR_MATRIX_1] = 'CM1_PLACEHOLDER';
        $tags[$wbTag]                 = 'WB_PLACEHOLDER';

        ksort($tags);

        $entryCount = count($tags);
        $ifdSize    = 2 + ($entryCount * 12) + 4;
        $curOffset  = $ifdOffset + $ifdSize;
        $outOfLine  = '';

        // ColorMatrix1: SRATIONAL, count = colorPlanes * 3
        $cm1Count = $colorPlanes * 3;
        $cm1Data  = '';

        for ($i = 0; $i < $cm1Count; ++$i) {
            $cm1Data .= pack('VV', 1, 1); // SRATIONAL 1/1
        }

        $tags[DngTag::COLOR_MATRIX_1] = pack('v', DngTag::COLOR_MATRIX_1)
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', $cm1Count)
            . pack('V', $curOffset);
        $outOfLine .= $cm1Data;
        $curOffset += strlen($cm1Data);

        // WB tag data
        $wbData = '';

        for ($i = 0; $i < $wbCount; ++$i) {
            if ($wbType === TiffConst::TYPE_RATIONAL || $wbType === TiffConst::TYPE_SRATIONAL) {
                $wbData .= pack('VV', 1, 3);
            } elseif ($wbType === TiffConst::TYPE_SHORT) {
                $wbData .= pack('v', 1);
            } else {
                $wbData .= pack('C', 0x31); // Single byte fallback for wrong-type tests
            }
        }

        $totalSz = strlen($wbData);

        if ($totalSz <= 4) {
            $valOrOffset = str_pad($wbData, 4, "\x00");
        } else {
            $valOrOffset = pack('V', $curOffset);
            $outOfLine .= $wbData;
        }

        $tags[$wbTag] = pack('v', $wbTag)
            . pack('v', $wbType)
            . pack('V', $wbCount)
            . $valOrOffset;

        ksort($tags);

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

    /**
     * IFD0-only DNG tag in additional IFD triggers ParseError.
     */
    #[Test]
    public function rejectsIfd0OnlyTagInAdditionalIfd(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1488);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTagInSecondIfd(DngTag::AS_SHOT_WHITE_XY),
        );
    }

    /**
     * DNG tag allowed in non-IFD0 contexts does not trigger the role error.
     */
    #[Test]
    public function acceptsNonRestrictedTagInAdditionalIfd(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithTagInSecondIfd(DngTag::CALIBRATION_ILLUMINANT_1),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a two-IFD TIFF where the second IFD contains a SHORT[1]
     * tag with the given tag ID.
     */
    private function buildTiffWithTagInSecondIfd(int $extraTag): string
    {
        $ifdOffset   = 8;
        $ifd0Entries = 2;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;
        $ifd1Offset  = $ifdOffset + $ifd0Size;

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd1Offset);

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 50) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 50) . pack('v', 0),
            $extraTag => pack('v', $extraTag)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 17) . pack('v', 0),
        ];

        ksort($tags);

        $ifd1 = pack('v', count($tags));

        foreach ($tags as $entry) {
            $ifd1 .= $entry;
        }

        $ifd1 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1;
    }

    /**
     * JXLEffort out of range triggers ParseError.
     */
    #[Test]
    public function rejectsJxlEffortOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1489);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(effort: 10),
        );
    }

    /**
     * JXLDecodeSpeed out of range triggers ParseError.
     */
    #[Test]
    public function rejectsJxlDecodeSpeedOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1489);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(decodeSpeed: 5),
        );
    }

    /**
     * JXL tags present with non-JXL compression triggers ParseError.
     */
    #[Test]
    public function rejectsJxlTagsWithNonJxlCompression(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1490);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(compression: 7),
        );
    }

    /**
     * Valid JXL tags with JPEG XL compression parses successfully.
     */
    #[Test]
    public function acceptsValidJxlTagsWithJxlCompression(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * CFA photometric with missing CFARepeatPatternDim triggers ParseError.
     */
    #[Test]
    public function rejectsCfaPhotometricMissingRepeatPatternDim(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1491);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(includeRepeatPatternDim: false),
        );
    }

    /**
     * CFA photometric with missing CFAPattern triggers ParseError.
     */
    #[Test]
    public function rejectsCfaPhotometricMissingCfaPattern(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1491);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(includeCfaPattern: false),
        );
    }

    /**
     * CFA photometric with both required tags parses successfully.
     */
    #[Test]
    public function acceptsCfaPhotometricWithBothRequiredTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Non-CFA photometric without CFA tags parses successfully.
     */
    #[Test]
    public function acceptsNonCfaPhotometricWithoutCfaTags(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(photometric: 2, includeRepeatPatternDim: false, includeCfaPattern: false),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Builds a two-IFD TIFF where the second IFD contains Compression
     * and JXL tuning tags.
     */
    private function buildTiffWithJxlTags(
        int $compression = 52546,
        float $distance = 0.0,
        int $effort = 7,
        int $decodeSpeed = 4,
    ): string {
        $ifdOffset   = 8;
        $ifd0Entries = 2;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;
        $ifd1Offset  = $ifdOffset + $ifd0Size;
        $ifd1Entries = 2;
        $ifd1Size    = 2 + ($ifd1Entries * 12) + 4;
        $ifd2Offset  = $ifd1Offset + $ifd1Size;

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd1Offset);

        $ifd1 = pack('v', $ifd1Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd2Offset);

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::COMPRESSION => pack('v', ExifTag::COMPRESSION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $compression) . pack('v', 0),
            DngTag::JXL_DISTANCE => pack('v', DngTag::JXL_DISTANCE)
                . pack('v', TiffConst::TYPE_FLOAT)
                . pack('V', 1)
                . pack('g', $distance),
            DngTag::JXL_EFFORT => pack('v', DngTag::JXL_EFFORT)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $effort),
            DngTag::JXL_DECODE_SPEED => pack('v', DngTag::JXL_DECODE_SPEED)
                . pack('v', TiffConst::TYPE_LONG)
                . pack('V', 1)
                . pack('V', $decodeSpeed),
        ];

        ksort($tags);

        $ifd2 = pack('v', count($tags));

        foreach ($tags as $entry) {
            $ifd2 .= $entry;
        }

        $ifd2 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1
            . $ifd2;
    }

    /**
     * Builds a 3-IFD TIFF where IFD2 has PhotometricInterpretation and
     * optionally CFARepeatPatternDim and CFAPattern.
     */
    private function buildTiffWithCfaPhotometric(
        int $photometric = 32803,
        bool $includeRepeatPatternDim = true,
        bool $includeCfaPattern = true,
    ): string {
        $ifdOffset   = 8;
        $ifd0Entries = 2;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;
        $ifd1Offset  = $ifdOffset + $ifd0Size;
        $ifd1Entries = 2;
        $ifd1Size    = 2 + ($ifd1Entries * 12) + 4;
        $ifd2Offset  = $ifd1Offset + $ifd1Size;

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd1Offset);

        $ifd1 = pack('v', $ifd1Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('V', $ifd2Offset);

        $tags = [
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
                . pack('v', $photometric) . pack('v', 0),
        ];

        if ($includeRepeatPatternDim) {
            $tags[DngTag::CFA_REPEAT_PATTERN_DIM] = pack('v', DngTag::CFA_REPEAT_PATTERN_DIM)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 2)
                . pack('v', 2) . pack('v', 2);
        }

        if ($includeCfaPattern) {
            $tags[ExifTag::CFA_PATTERN] = pack('v', ExifTag::CFA_PATTERN)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', 4)
                . pack('C4', 0, 1, 1, 2);
        }

        ksort($tags);

        $ifd2 = pack('v', count($tags));

        foreach ($tags as $entry) {
            $ifd2 .= $entry;
        }

        $ifd2 .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1
            . $ifd2;
    }
}
