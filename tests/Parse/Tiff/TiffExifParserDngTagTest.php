<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Tiff;

use InvalidArgumentException;
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
            // IlluminantData: UNDEFINED, DataType=0 chromaticity (18 bytes)
            $illuminantPayload = pack('v', 0) . pack('V', 1) . pack('V', 3) . pack('V', 1) . pack('V', 3);
            $illuminantOffset  = $ifdOffset + 2 + ($entryCount * 12) + 4;
            $tags[$dataTag]    = pack('v', $dataTag)
                . pack('v', TiffConst::TYPE_UNDEFINED)
                . pack('V', strlen($illuminantPayload))
                . pack('V', $illuminantOffset);
        }

        ksort($tags);

        $ifdData = pack('v', $entryCount);
        foreach ($tags as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0); // next IFD

        $result = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData;

        if (isset($illuminantPayload)) {
            $result .= $illuminantPayload;
        }

        return $result;
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
            $this->buildTiffWithRoleIfd(65540, 52527, includeSemanticName: true),
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
        bool $includeSemanticName = false,
    ): string {
        $ifdOffset   = 8;
        $ifd0Entries = 2;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;
        $ifd1Offset  = $ifdOffset + $ifd0Size;

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

        $semanticName = pack('Z*', 'MaskName0');
        $ifd1Entries  = count($tags);

        if ($includeSemanticName) {
            ++$ifd1Entries;
        }

        $ifd1Size       = 2 + ($ifd1Entries * 12) + 4;
        $nameDataOffset = $ifd1Offset + $ifd1Size;

        if ($includeSemanticName) {
            $tags[DngTag::SEMANTIC_NAME] = pack('v', DngTag::SEMANTIC_NAME)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($semanticName))
                . pack('V', $nameDataOffset);
        }

        ksort($tags);

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

        $ifd1 = pack('v', $ifd1Entries);

        foreach ($tags as $entry) {
            $ifd1 .= $entry;
        }

        $ifd1 .= pack('V', 0);

        $result = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $ifd1;

        if ($includeSemanticName) {
            $result .= $semanticName;
        }

        return $result;
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
        ?string $wbPayload = null,
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
        if ($wbPayload !== null) {
            $wbData = $wbPayload;
        } else {
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
     * JXL compression with disallowed SamplesPerPixel triggers ParseError.
     */
    #[Test]
    public function rejectsJxlWithUnsupportedSamplesPerPixel(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1492);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(samplesPerPixel: 4),
        );
    }

    /**
     * JXL compression with disallowed PhotometricInterpretation triggers ParseError.
     */
    #[Test]
    public function rejectsJxlWithUnsupportedPhotometric(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1493);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(photometric: 6),
        );
    }

    /**
     * JXL compression with valid SamplesPerPixel and Photometric parses.
     */
    #[Test]
    public function acceptsJxlWithValidSamplesAndPhotometric(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithJxlTags(samplesPerPixel: 3, photometric: 2),
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
     * RGB CFA without CFAPlaneColor parses successfully (no false positive).
     */
    #[Test]
    public function acceptsRgbCfaWithoutCfaPlaneColor(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Non-RGB CFA without CFAPlaneColor triggers ParseError.
     */
    #[Test]
    public function rejectsNonRgbCfaWithoutCfaPlaneColor(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1497);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(cfaColors: pack('C4', 0, 1, 3, 4)),
        );
    }

    /**
     * Non-RGB CFA with CFAPlaneColor parses successfully.
     */
    #[Test]
    public function acceptsNonRgbCfaWithCfaPlaneColor(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithCfaPhotometric(cfaColors: pack('C4', 0, 1, 3, 4), includeCfaPlaneColor: true),
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
     * DNG-specific tags without DNGVersion in IFD0 triggers ParseError.
     */
    #[Test]
    public function rejectsDngTagsWithoutDngVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1498);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithDngTagsNoDngVersion(),
        );
    }

    /**
     * DNG with DNGVersion in IFD0 parses successfully (already tested elsewhere but explicit).
     */
    #[Test]
    public function acceptsDngWithDngVersionInIfd0(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildClassicDngTiff(),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::DNG_VERSION));
    }

    /**
     * NoiseProfile with S_i <= 0 triggers ParseError.
     */
    #[Test]
    public function rejectsNoiseProfileWithNonPositiveS(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1499);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithNoiseProfile([0.0, 0.001]),
        );
    }

    /**
     * NoiseProfile with O_i < 0 triggers ParseError.
     */
    #[Test]
    public function rejectsNoiseProfileWithNegativeO(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1499);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithNoiseProfile([0.001, -0.0001]),
        );
    }

    /**
     * NoiseProfile with odd count triggers ParseError.
     */
    #[Test]
    public function rejectsNoiseProfileWithOddCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1500);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithNoiseProfile([0.001, 0.0, 0.002]),
        );
    }

    /**
     * Valid global NoiseProfile (count=2) parses successfully.
     */
    #[Test]
    public function acceptsValidGlobalNoiseProfile(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithNoiseProfile([0.001, 0.0]),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * ProfileDynamicRange with invalid payload length triggers ParseError.
     */
    #[Test]
    public function rejectsProfileDynamicRangeBadLength(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1505);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 1) . pack('v', 0)),
        );
    }

    /**
     * ProfileDynamicRange with unsupported Version triggers ParseError.
     */
    #[Test]
    public function rejectsProfileDynamicRangeBadVersion(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1506);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 2) . pack('v', 0) . pack('g', 0.0)),
        );
    }

    /**
     * ProfileDynamicRange with unsupported DynamicRange triggers ParseError.
     */
    #[Test]
    public function rejectsProfileDynamicRangeBadRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1507);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 1) . pack('v', 3) . pack('g', 0.0)),
        );
    }

    /**
     * SDR ProfileDynamicRange with HintMaxOutputValue > 1 triggers ParseError.
     */
    #[Test]
    public function rejectsProfileDynamicRangeSdrHintAboveOne(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1508);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 1) . pack('v', 0) . pack('g', 1.5)),
        );
    }

    /**
     * Valid SDR ProfileDynamicRange parses successfully.
     */
    #[Test]
    public function acceptsValidSdrProfileDynamicRange(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 1) . pack('v', 0) . pack('g', 0.5)),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Valid HDR ProfileDynamicRange parses successfully.
     */
    #[Test]
    public function acceptsValidHdrProfileDynamicRange(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileDynamicRange(pack('v', 1) . pack('v', 1) . pack('g', 4.0)),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Valid IlluminantData1 DataType=0 (chromaticity) parses successfully.
     */
    #[Test]
    public function acceptsIlluminantDataChromaticity(): void
    {
        // DataType=0: SHORT(0) + x RATIONAL(1/3) + y RATIONAL(1/3)
        $payload = pack('v', 0) . pack('V', 1) . pack('V', 3) . pack('V', 1) . pack('V', 3);

        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithIlluminantData($payload),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * Valid IlluminantData1 DataType=1 (spectral) with NumLambda >= 2 parses successfully.
     */
    #[Test]
    public function acceptsIlluminantDataSpectralValid(): void
    {
        // DataType=1: SHORT(1) + LONG(2) + MinLambda RATIONAL + LambdaSpacing RATIONAL + 2 samples
        $payload = pack('v', 1) . pack('V', 2)
            . pack('V', 380) . pack('V', 1)  // MinLambda
            . pack('V', 5) . pack('V', 1)    // LambdaSpacing
            . pack('V', 100) . pack('V', 1)  // sample 1
            . pack('V', 200) . pack('V', 1); // sample 2

        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithIlluminantData($payload),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * IlluminantData1 DataType=1 with NumLambda < 2 triggers ParseError.
     */
    #[Test]
    public function rejectsIlluminantDataSpectralTooFewLambda(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1503);

        // DataType=1 with NumLambda=1
        $payload = pack('v', 1) . pack('V', 1)
            . pack('V', 380) . pack('V', 1)
            . pack('V', 5) . pack('V', 1)
            . pack('V', 100) . pack('V', 1);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithIlluminantData($payload),
        );
    }

    /**
     * IlluminantData1 with unknown DataType triggers ParseError.
     */
    #[Test]
    public function rejectsIlluminantDataUnknownDataType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1504);

        $payload = pack('v', 5) . str_repeat("\0", 16);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithIlluminantData($payload),
        );
    }

    /**
     * ProfileHueSatMapData1 count mismatch vs dims triggers ParseError.
     */
    #[Test]
    public function rejectsHueSatMapDataCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1501);

        // dims = 2*2*1 = 4 triples = 12 floats; provide only 9
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMap(
                [2, 2, 1],
                array_fill(0, 9, 0.0),
            ),
        );
    }

    /**
     * ProfileHueSatMapData1 with zero-saturation valueScale != 1.0 triggers ParseError.
     */
    #[Test]
    public function rejectsHueSatMapZeroSatValueScaleNotOne(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1502);

        // dims 2*2*1 = 4 triples; sat index 0 = zero-saturation row
        // Triple at index 0: (hue=0, sat=1.0, val=0.5) — valueScale should be 1.0
        $data = [
            0.0, 1.0, 0.5,  // sat=0 row, valueScale=0.5 INVALID
            0.0, 1.0, 1.0,  // sat=1 row, ok
            0.0, 1.0, 0.5,  // sat=0 row, valueScale=0.5 INVALID
            0.0, 1.0, 1.0,  // sat=1 row, ok
        ];

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMap([2, 2, 1], $data),
        );
    }

    /**
     * Valid ProfileHueSatMapData1 parses successfully.
     */
    #[Test]
    public function acceptsValidHueSatMapData(): void
    {
        // dims 2*2*1 = 4 triples; sat index 0 has valueScale=1.0
        $data = [
            0.0, 1.0, 1.0,  // sat=0 row
            0.0, 1.0, 1.2,  // sat=1 row
            0.0, 1.0, 1.0,  // sat=0 row
            0.0, 1.0, 0.9,  // sat=1 row
        ];

        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMap([2, 2, 1], $data),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * ColorimetricReference value 0 with DNG 1.2+ parses successfully.
     */
    #[Test]
    public function acceptsColorimetricReferenceZero(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithColorimetricReference(0, [1, 2, 0, 0]),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * ColorimetricReference value 1 with DNG 1.2+ parses successfully.
     */
    #[Test]
    public function acceptsColorimetricReferenceOne(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithColorimetricReference(1, [1, 2, 0, 0]),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * ColorimetricReference value 2 with DNG backward version < 1.7 triggers ParseError.
     */
    #[Test]
    public function rejectsColorimetricReference2BelowDng17(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1495);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithColorimetricReference(2, [1, 6, 0, 0]),
        );
    }

    /**
     * ColorimetricReference value 2 with DNG 1.7+ parses successfully.
     */
    #[Test]
    public function acceptsColorimetricReference2WithDng17(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithColorimetricReference(2, [1, 7, 0, 0]),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * ColorimetricReference out-of-domain value triggers ParseError.
     */
    #[Test]
    public function rejectsColorimetricReferenceOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1494);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithColorimetricReference(3, [1, 7, 0, 0]),
        );
    }

    /**
     * DNGBackwardVersion above supported reader version triggers ParseError.
     */
    #[Test]
    public function rejectsDngBackwardVersionAboveSupported(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1496);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBackwardVersion([2, 0, 0, 0]),
        );
    }

    /**
     * DNGBackwardVersion at exactly the supported version parses successfully.
     */
    #[Test]
    public function acceptsDngBackwardVersionAtSupported(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBackwardVersion([1, 7, 1, 0]),
        );

        self::assertNotNull($parsed->ifd0->get(ExifTag::IMAGE_WIDTH));
    }

    /**
     * DNGBackwardVersion below supported reader version parses successfully.
     */
    #[Test]
    public function acceptsDngBackwardVersionBelowSupported(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBackwardVersion([1, 4, 0, 0]),
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
        ?int $samplesPerPixel = null,
        ?int $photometric = null,
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

        if ($samplesPerPixel !== null) {
            $tags[ExifTag::SAMPLES_PER_PIXEL] = pack('v', ExifTag::SAMPLES_PER_PIXEL)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $samplesPerPixel) . pack('v', 0);
        }

        if ($photometric !== null) {
            $tags[ExifTag::PHOTOMETRIC_INTERPRETATION] = pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $photometric) . pack('v', 0);
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

    /**
     * Builds a 3-IFD TIFF where IFD2 has PhotometricInterpretation and
     * optionally CFARepeatPatternDim and CFAPattern.
     */
    private function buildTiffWithCfaPhotometric(
        int $photometric = 32803,
        bool $includeRepeatPatternDim = true,
        bool $includeCfaPattern = true,
        ?string $cfaColors = null,
        bool $includeCfaPlaneColor = false,
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
            $patternBytes               = $cfaColors ?? pack('C4', 0, 1, 1, 2);
            $tags[ExifTag::CFA_PATTERN] = pack('v', ExifTag::CFA_PATTERN)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', strlen($patternBytes))
                . str_pad($patternBytes, 4, "\0");
        }

        if ($includeCfaPlaneColor) {
            $tags[DngTag::CFA_PLANE_COLOR] = pack('v', DngTag::CFA_PLANE_COLOR)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', 4)
                . pack('C4', 0, 1, 3, 4);
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

    /**
     * Builds a DNG TIFF with ColorimetricReference and configurable backward version.
     *
     * @param int       $colorimetricRef Colorimetric reference value
     * @param list<int> $bwVer           Four-byte backward version
     */
    private function buildTiffWithColorimetricReference(int $colorimetricRef, array $bwVer): string
    {
        $ifdOffset         = 8;
        $entryCount        = 7;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera');
        $modelOffset       = $ifdOffset + $ifdSize;

        $bwVersionPacked = pack('C4', $bwVer[0], $bwVer[1], $bwVer[2], $bwVer[3]);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . $bwVersionPacked
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::COLORIMETRIC_REFERENCE)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $colorimetricRef) . pack('v', 0)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a minimal DNG TIFF with configurable DNGBackwardVersion.
     *
     * @param list<int> $bwVer Four-byte backward version
     */
    private function buildDngWithBackwardVersion(array $bwVer): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', $bwVer[0], $bwVer[1], $bwVer[2], $bwVer[3])
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a TIFF with DNG-specific tags (UniqueCameraModel) but no DNGVersion.
     */
    private function buildTiffWithDngTagsNoDngVersion(): string
    {
        $ifdOffset         = 8;
        $entryCount        = 3;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a DNG TIFF with a NoiseProfile of given DOUBLE values.
     *
     * @param list<float> $doubles NoiseProfile coefficient values
     */
    private function buildDngWithNoiseProfile(array $doubles): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $noiseData         = '';

        foreach ($doubles as $d) {
            $noiseData .= pack('e', $d);
        }

        $noiseOffset = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::NOISE_PROFILE)
            . pack('v', TiffConst::TYPE_DOUBLE)
            . pack('V', count($doubles))
            . pack('V', $noiseOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $noiseData;
    }

    /**
     * Builds a DNG TIFF with ProfileHueSatMapDims and ProfileHueSatMapData1.
     *
     * @param list<int>   $dims   Three LONG values [hue, sat, val]
     * @param list<float> $floats FLOAT data for ProfileHueSatMapData1
     */
    private function buildDngWithHueSatMap(array $dims, array $floats): string
    {
        $ifdOffset         = 8;
        $entryCount        = 7;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);
        $dimsData          = pack('V3', $dims[0], $dims[1], $dims[2]);
        $dataOffset        = $dimsOffset + strlen($dimsData);
        $floatData         = '';

        foreach ($floats as $f) {
            $floatData .= pack('g', $f);
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_HUE_SAT_MAP_DIMS)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('v', DngTag::PROFILE_HUE_SAT_MAP_DATA_1)
            . pack('v', TiffConst::TYPE_FLOAT)
            . pack('V', count($floats))
            . pack('V', $dataOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData
            . $floatData;
    }

    /**
     * Builds a DNG TIFF with IlluminantData1 payload of given bytes.
     */
    private function buildDngWithIlluminantData(string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::ILLUMINANT_DATA_1)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Builds a DNG TIFF with a ProfileDynamicRange payload.
     */
    private function buildDngWithProfileDynamicRange(string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_DYNAMIC_RANGE)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Accepts a valid ASCII-typed ProfileGroupName with null terminator.
     */
    #[Test]
    public function acceptsProfileGroupNameAscii(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithProfileGroupName(TiffConst::TYPE_ASCII, "MyGroup\0"),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts a valid BYTE-typed ProfileGroupName with null terminator.
     */
    #[Test]
    public function acceptsProfileGroupNameByte(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithProfileGroupName(TiffConst::TYPE_BYTE, "MyGroup\0"),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ProfileGroupName with unsupported TIFF type.
     */
    #[Test]
    public function rejectsProfileGroupNameInvalidType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1509);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileGroupName(TiffConst::TYPE_SHORT, pack('v', 0)),
        );
    }

    /**
     * Rejects BYTE-typed ProfileGroupName missing null terminator.
     */
    #[Test]
    public function rejectsProfileGroupNameByteNoNul(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1510);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfileGroupName(TiffConst::TYPE_BYTE, 'NoNul'),
        );
    }

    /**
     * Builds a DNG TIFF with a ProfileGroupName tag.
     */
    private function buildDngWithProfileGroupName(int $type, string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_GROUP_NAME)
            . pack('v', $type)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Accepts valid ProfileHueSatMapDims with conforming lower bounds.
     */
    #[Test]
    public function acceptsProfileHueSatMapDimsValid(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithHueSatMapDimsOnly([1, 2, 1]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ProfileHueSatMapDims with HueDivisions = 0.
     */
    #[Test]
    public function rejectsHueSatMapDimsHueZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1512);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMapDimsOnly([0, 2, 1]),
        );
    }

    /**
     * Rejects ProfileHueSatMapDims with SaturationDivisions < 2.
     */
    #[Test]
    public function rejectsHueSatMapDimsSatTooLow(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1513);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMapDimsOnly([1, 1, 1]),
        );
    }

    /**
     * Rejects ProfileHueSatMapDims with ValueDivisions = 0.
     */
    #[Test]
    public function rejectsHueSatMapDimsValueZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1514);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithHueSatMapDimsOnly([1, 2, 0]),
        );
    }

    /**
     * Builds a DNG TIFF with only ProfileHueSatMapDims (no data tag).
     *
     * @param array{0: int, 1: int, 2: int} $dims Hue, saturation, value divisions
     */
    private function buildDngWithHueSatMapDimsOnly(array $dims): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);
        $dimsData          = pack('V3', $dims[0], $dims[1], $dims[2]);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_HUE_SAT_MAP_DIMS)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData;
    }

    /**
     * Accepts a single-profile DNG without ProfileName.
     */
    #[Test]
    public function acceptsSingleProfileWithoutName(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithProfiles(1, false),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts a multi-profile DNG with all ProfileNames present.
     */
    #[Test]
    public function acceptsMultiProfileWithAllNames(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithProfiles(2, true),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects a multi-profile DNG with one missing ProfileName.
     */
    #[Test]
    public function rejectsMultiProfileMissingName(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1515);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithProfiles(2, false),
        );
    }

    /**
     * Builds a DNG with one or more camera profiles (identified by ColorMatrix1).
     *
     * @param int  $profileCount Number of camera profiles (1 = IFD0 only, 2 = IFD0 + one additional)
     * @param bool $includeNames Whether to include ProfileName in each profile IFD
     */
    private function buildDngWithProfiles(int $profileCount, bool $includeNames): string
    {
        $ifdOffset         = 8;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');

        // ColorMatrix1: SRATIONAL[3] = 3 rationals × 8 bytes = 24 bytes
        $matrixData  = pack('V6', 1, 1, 0, 1, 0, 1);
        $profileName = pack('Z*', 'Profile00');

        // IFD0: ImageWidth, ImageLength, Orientation, DngVersion, UniqueCameraModel,
        //       ColorMatrix1, and optionally ProfileName
        $ifd0EntryCount = $includeNames ? 7 : 6;
        $ifd0Size       = 2 + (12 * $ifd0EntryCount) + 4;
        $modelOffset    = $ifdOffset + $ifd0Size;
        $matrixOffset   = $modelOffset + strlen($uniqueCameraModel);
        $nameOffset     = $matrixOffset + strlen($matrixData);

        $afterIfd0Data = $uniqueCameraModel . $matrixData;
        if ($includeNames) {
            $afterIfd0Data .= $profileName;
        }

        // Build additional profile IFD (IFD2) if needed, with IFD1 as minimal thumbnail
        if ($profileCount > 1) {
            $ifd1Offset     = $ifdOffset + $ifd0Size + strlen($afterIfd0Data);
            $ifd1EntryCount = 2;
            $ifd1Size       = 2 + (12 * $ifd1EntryCount) + 4;

            $ifd2Offset    = $ifd1Offset + $ifd1Size;
            $profile2Name  = pack('Z*', 'Profile01');
            $matrix2Offset = $ifd2Offset + 2 + (12 * ($includeNames ? 2 : 1)) + 4;
            $name2Offset   = $matrix2Offset + strlen($matrixData);
        } else {
            $ifd1Offset = 0;
        }

        $ifd0 = pack('v', $ifd0EntryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::COLOR_MATRIX_1)
            . pack('v', TiffConst::TYPE_SRATIONAL)
            . pack('V', 3)
            . pack('V', $matrixOffset);

        if ($includeNames) {
            $ifd0 .= pack('v', DngTag::PROFILE_NAME)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($profileName))
                . pack('V', $nameOffset);
        }

        $ifd0 .= pack('V', $ifd1Offset);

        $result = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $afterIfd0Data;

        if ($profileCount > 1) {
            // IFD1 (minimal thumbnail)
            $result .= pack('v', $ifd1EntryCount)
                . pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0)
                . pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0)
                . pack('V', $ifd2Offset);

            // IFD2 (second profile with ColorMatrix1)
            $ifd2EntryCount = $includeNames ? 2 : 1;
            $result .= pack('v', $ifd2EntryCount)
                . pack('v', DngTag::COLOR_MATRIX_1)
                . pack('v', TiffConst::TYPE_SRATIONAL)
                . pack('V', 3)
                . pack('V', $matrix2Offset);

            if ($includeNames) {
                $result .= pack('v', DngTag::PROFILE_NAME)
                    . pack('v', TiffConst::TYPE_ASCII)
                    . pack('V', strlen($profile2Name))
                    . pack('V', $name2Offset);
            }

            $result .= pack('V', 0)
                . $matrixData;

            if ($includeNames) {
                $result .= $profile2Name;
            }
        }

        return $result;
    }

    /**
     * Accepts valid ProfileGainTableMap2 with DataType 0 (UINT8).
     */
    #[Test]
    public function acceptsProfileGainTableMap2DataType0(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithGainTableMap2(dataType: 0, mapPointsV: 2, mapPointsH: 2, mapPointsN: 1),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts valid ProfileGainTableMap2 with DataType 3 (FLOAT32).
     */
    #[Test]
    public function acceptsProfileGainTableMap2DataType3(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithGainTableMap2(dataType: 3, mapPointsV: 2, mapPointsH: 2, mapPointsN: 1),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ProfileGainTableMap2 with invalid DataType.
     */
    #[Test]
    public function rejectsGainTableMap2InvalidDataType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1517);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithGainTableMap2(dataType: 5),
        );
    }

    /**
     * Rejects ProfileGainTableMap2 with Gamma out of range.
     */
    #[Test]
    public function rejectsGainTableMap2GammaOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1518);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithGainTableMap2(gamma: 5.0),
        );
    }

    /**
     * Rejects ProfileGainTableMap2 with mismatched count.
     */
    #[Test]
    public function rejectsGainTableMap2CountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1519);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithGainTableMap2(dataType: 0, mapPointsV: 2, mapPointsH: 2, mapPointsN: 1, extraBytes: 3),
        );
    }

    /**
     * Builds a DNG TIFF with a ProfileGainTableMap2 payload.
     */
    private function buildDngWithGainTableMap2(
        int $dataType = 0,
        int $mapPointsV = 1,
        int $mapPointsH = 1,
        int $mapPointsN = 1,
        float $gamma = 1.0,
        int $extraBytes = 0,
    ): string {
        $bytesPerElement = match ($dataType) {
            0 => 1,
            1, 2 => 2,
            3       => 4,
            default => 1,
        };

        $gainDataSize = $bytesPerElement * $mapPointsV * $mapPointsH * $mapPointsN + $extraBytes;
        $gainData     = str_repeat("\x01", $gainDataSize);

        $header = pack('V', $mapPointsV)
            . pack('V', $mapPointsH)
            . pack('e', 1.0)                                 // MapSpacingV
            . pack('e', 1.0)                                 // MapSpacingH
            . pack('e', 0.0)                                 // MapOriginV
            . pack('e', 0.0)                                 // MapOriginH
            . pack('V', $mapPointsN)
            . pack('g5', 0.333, 0.333, 0.333, 0.0, 0.0)     // MapInputWeights[5]
            . pack('V', $dataType)
            . pack('g', $gamma)
            . pack('g', 0.0)                                 // GainMin
            . pack('g', 1.0);                                // GainMax

        $payload = $header . $gainData;

        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_GAIN_TABLE_MAP_2)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Rejects ProfileGainTableMap in IFD0 (restricted to Raw IFDs).
     */
    #[Test]
    public function rejectsGainTableMapInIfd0(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1520);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithGainTableMapInIfd0(),
        );
    }

    /**
     * Accepts ProfileGainTableMap in an additional (Raw) IFD.
     */
    #[Test]
    public function acceptsGainTableMapInRawIfd(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithGainTableMapInRawIfd(),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Builds a DNG with ProfileGainTableMap in IFD0 (invalid placement).
     */
    private function buildDngWithGainTableMapInIfd0(): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payload           = str_repeat("\x00", 80);
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_GAIN_TABLE_MAP)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Builds a DNG with ProfileGainTableMap in an additional (Raw) IFD.
     */
    private function buildDngWithGainTableMapInRawIfd(): string
    {
        $ifdOffset   = 8;
        $ifd0Entries = 5;
        $ifd0Size    = 2 + ($ifd0Entries * 12) + 4;

        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifd0Size;

        $ifd1Offset  = $modelOffset + strlen($uniqueCameraModel);
        $ifd1Entries = 2;
        $ifd1Size    = 2 + ($ifd1Entries * 12) + 4;

        $ifd2Offset  = $ifd1Offset + $ifd1Size;
        $ifd2Entries = 3;
        $ifd2Size    = 2 + ($ifd2Entries * 12) + 4;

        $payload       = str_repeat("\x00", 80);
        $payloadOffset = $ifd2Offset + $ifd2Size;

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
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

        $ifd2 = pack('v', $ifd2Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', DngTag::PROFILE_GAIN_TABLE_MAP)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $uniqueCameraModel
            . $ifd1
            . $ifd2
            . $payload;
    }

    /**
     * Accepts a valid ImageSequenceInfo payload.
     */
    #[Test]
    public function acceptsValidImageSequenceInfo(): void
    {
        $payload = "ABCDEFGH\0"   // SequenceID (8 chars + NUL)
            . "burst\0"           // SequenceType (5 chars + NUL)
            . "\0"                // FrameInfo (empty + NUL)
            . pack('N', 0)        // Index (big-endian)
            . pack('N', 10)       // Count (big-endian)
            . "\x01";             // Final

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithImageSequenceInfo($payload),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ImageSequenceInfo with SequenceID shorter than 8 chars.
     */
    #[Test]
    public function rejectsImageSequenceInfoShortId(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1522);

        $payload = "SHORT\0"      // SequenceID (5 chars — too short)
            . "burst\0"
            . "\0"
            . pack('N', 0)
            . pack('N', 1)
            . "\x00";

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageSequenceInfo($payload),
        );
    }

    /**
     * Rejects ImageSequenceInfo with missing NUL terminator in SequenceID.
     */
    #[Test]
    public function rejectsImageSequenceInfoNoNulInId(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1521);

        // Payload with no NUL byte at all
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageSequenceInfo('ABCDEFGHIJKLMNOP'),
        );
    }

    /**
     * Rejects ImageSequenceInfo with truncated trailing fields.
     */
    #[Test]
    public function rejectsImageSequenceInfoTruncated(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1526);

        $payload = "ABCDEFGH\0"
            . "burst\0"
            . "\0"
            . pack('N', 0);       // Only Index, missing Count + Final

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageSequenceInfo($payload),
        );
    }

    /**
     * Builds a DNG TIFF with an ImageSequenceInfo payload.
     */
    private function buildDngWithImageSequenceInfo(string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::IMAGE_SEQUENCE_INFO)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Accepts a valid minimal RGBTables payload.
     */
    #[Test]
    public function acceptsValidRgbTables(): void
    {
        // NumTables=1, CompositeMethod=0, nameLen=0, Div=2, PixelType=0,
        // Gamma=0, ColorPrimaries=0, GamutExtension=0, data=2^3*3=24 bytes
        $payload = pack('V', 1) . pack('V', 0)
            . pack('v', 0)
            . "\x02\x00\x00\x00\x00"
            . str_repeat("\x80", 24);

        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects RGBTables with NumTables out of range.
     */
    #[Test]
    public function rejectsRgbTablesNumTablesZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1528);

        $payload = pack('V', 0) . pack('V', 0);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );
    }

    /**
     * Rejects RGBTables with invalid CompositeMethod.
     */
    #[Test]
    public function rejectsRgbTablesInvalidCompositeMethod(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1529);

        $payload = pack('V', 1) . pack('V', 2);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );
    }

    /**
     * Rejects RGBTables with Divisions out of range.
     */
    #[Test]
    public function rejectsRgbTablesDivisionsOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1531);

        $payload = pack('V', 1) . pack('V', 0)
            . pack('v', 0)
            . "\x01\x00\x00\x00\x00"
            . str_repeat("\x00", 3);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );
    }

    /**
     * Rejects RGBTables with invalid PixelType.
     */
    #[Test]
    public function rejectsRgbTablesInvalidPixelType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1532);

        $payload = pack('V', 1) . pack('V', 0)
            . pack('v', 0)
            . "\x02\x03\x00\x00\x00"
            . str_repeat("\x00", 24);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );
    }

    /**
     * Rejects RGBTables payload length mismatch.
     */
    #[Test]
    public function rejectsRgbTablesLengthMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1537);

        // Valid header but extra trailing bytes
        $payload = pack('V', 1) . pack('V', 0)
            . pack('v', 0)
            . "\x02\x00\x00\x00\x00"
            . str_repeat("\x80", 24)
            . "\xFF";

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRgbTables($payload),
        );
    }

    /**
     * Builds a DNG TIFF with an RGBTables payload.
     */
    private function buildDngWithRgbTables(string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::RGB_TABLES)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', strlen($payload))
            . pack('V', $payloadOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $payload;
    }

    /**
     * Accepts a semantic mask IFD with valid SemanticName.
     */
    #[Test]
    public function acceptsSemanticMaskWithName(): void
    {
        $parser = new TiffExifParser();
        $parsed = $parser->parseFromBlob(
            $this->buildDngWithSemanticMask(true),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects a semantic mask IFD missing SemanticName.
     */
    #[Test]
    public function rejectsSemanticMaskMissingName(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1538);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSemanticMask(false),
        );
    }

    /**
     * Builds a 3-IFD DNG where IFD2 is a semantic mask IFD.
     *
     * @param bool $includeSemanticName Whether to include SemanticName in IFD2
     */
    private function buildDngWithSemanticMask(bool $includeSemanticName): string
    {
        $ifdOffset         = 8;
        $ifd0Entries       = 5;
        $ifd0Size          = 2 + ($ifd0Entries * 12) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifd0Size;

        $ifd1Offset  = $modelOffset + strlen($uniqueCameraModel);
        $ifd1Entries = 2;
        $ifd1Size    = 2 + ($ifd1Entries * 12) + 4;

        $ifd2Offset  = $ifd1Offset + $ifd1Size;
        $ifd2Entries = $includeSemanticName ? 5 : 4;
        $ifd2Size    = 2 + ($ifd2Entries * 12) + 4;

        $semanticName   = pack('Z*', 'SkinMask');
        $nameDataOffset = $ifd2Offset + $ifd2Size;

        // IFD0
        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', $ifd1Offset);

        // IFD1 (minimal thumbnail)
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

        // IFD2 (semantic mask): NewSubfileType=65540, Photometric=52527,
        // ImageWidth, ImageLength, and optionally SemanticName
        // Tags must be in ascending order:
        // NewSubfileType=0x00FE, PhotometricInterpretation=0x0106,
        // ImageWidth=0x0100, ImageLength=0x0101
        // Order: 0x00FE, 0x0100, 0x0101, 0x0106, 0xCD2E

        $ifd2 = pack('v', $ifd2Entries)
            . pack('v', TiffTag::NEW_SUBFILE_TYPE)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 65540)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 52527) . pack('v', 0);

        if ($includeSemanticName) {
            $ifd2 .= pack('v', DngTag::SEMANTIC_NAME)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($semanticName))
                . pack('V', $nameDataOffset);
        }

        $ifd2 .= pack('V', 0);

        $result = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $uniqueCameraModel
            . $ifd1
            . $ifd2;

        if ($includeSemanticName) {
            $result .= $semanticName;
        }

        return $result;
    }

    /**
     * Accepts a valid MaskSubArea in a semantic mask IFD.
     */
    #[Test]
    public function acceptsValidMaskSubArea(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithMaskSubArea(TiffConst::TYPE_LONG, 4, [0, 0, 100, 100]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts a MaskSubArea with geometry that exceeds bounds (silently ignored per spec).
     */
    #[Test]
    public function acceptsMaskSubAreaWithOutOfBoundsGeometry(): void
    {
        // T_crop + ImageLength (50 + 100 = 150) > H_full (120) → geometry invalid, tag ignored
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithMaskSubArea(TiffConst::TYPE_LONG, 4, [50, 60, 200, 120]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects MaskSubArea with wrong type.
     */
    #[Test]
    public function rejectsMaskSubAreaWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1541);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithMaskSubArea(TiffConst::TYPE_SHORT, 4, [0, 0, 100, 100]),
        );
    }

    /**
     * Rejects MaskSubArea with wrong count.
     */
    #[Test]
    public function rejectsMaskSubAreaWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1542);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithMaskSubArea(TiffConst::TYPE_LONG, 3, [0, 0, 100]),
        );
    }

    /**
     * Accepts a valid ImageStats payload with zero child entries.
     */
    #[Test]
    public function acceptsImageStatsWithZeroChildren(): void
    {
        // N=0 (big-endian LONG)
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats(pack('N', 0)),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts a valid ImageStats payload with one child entry.
     */
    #[Test]
    public function acceptsImageStatsWithOneChild(): void
    {
        // N=1, childTag=1, length=4, 4 bytes of float data
        $payload = pack('N', 1)
            . pack('N', 1)
            . pack('N', 4)
            . pack('G', 0.5);

        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats($payload),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ImageStats payload too short for child count.
     */
    #[Test]
    public function rejectsImageStatsTruncatedHeader(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1543);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats("\x00\x00"),
        );
    }

    /**
     * Rejects ImageStats child entry with truncated header.
     */
    #[Test]
    public function rejectsImageStatsTruncatedChildHeader(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1544);

        // N=1 but only 4 bytes follow (need 8 for header)
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats(pack('N', 1) . pack('N', 1)),
        );
    }

    /**
     * Rejects ImageStats child entry with truncated payload.
     */
    #[Test]
    public function rejectsImageStatsTruncatedChildPayload(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1545);

        // N=1, childTag=1, length=100 but no data follows
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats(pack('N', 1) . pack('N', 1) . pack('N', 100)),
        );
    }

    /**
     * Rejects ImageStats with duplicate child tag codes.
     */
    #[Test]
    public function rejectsImageStatsDuplicateChildTag(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1546);

        // N=2, both with childTag=1
        $payload = pack('N', 2)
            . pack('N', 1) . pack('N', 4) . pack('G', 0.5)
            . pack('N', 1) . pack('N', 4) . pack('G', 0.6);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithImageStats($payload),
        );
    }

    /**
     * Builds a minimal DNG with an ImageStats (0xCD46) payload in IFD0.
     *
     * @param string $payload Raw ImageStats payload bytes
     */
    private function buildDngWithImageStats(string $payload): string
    {
        $payloadLen        = strlen($payload);
        $inline            = $payloadLen <= 4;
        $ifdOffset         = 8;
        $ifd0Entries       = 6;
        $ifd0Size          = 2 + ($ifd0Entries * 12) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifd0Size;
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);

        // For inline values (<=4 bytes), pad payload to 4 bytes and store in value field
        $valueField = $inline
            ? str_pad($payload, 4, "\0")
            : pack('V', $payloadOffset);

        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::IMAGE_STATS)
            . pack('v', TiffConst::TYPE_UNDEFINED)
            . pack('V', $payloadLen)
            . $valueField
            . pack('V', 0);

        $result = 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $uniqueCameraModel;

        if (!$inline) {
            $result .= $payload;
        }

        return $result;
    }

    /**
     * Accepts a valid ProfileLookTableDims + ProfileLookTableData pair.
     */
    #[Test]
    public function acceptsValidLookTableDimsAndData(): void
    {
        // dims: Hue=1, Sat=2, Val=1 → data count = 1*2*1*3 = 6 FLOATs
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTable([1, 2, 1], 6),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ProfileLookTableDims with wrong type.
     */
    #[Test]
    public function rejectsLookTableDimsWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1547);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTableDimsOnly([1, 2, 1], TiffConst::TYPE_SHORT),
        );
    }

    /**
     * Rejects ProfileLookTableDims with SaturationDivisions < 2.
     */
    #[Test]
    public function rejectsLookTableDimsSatTooLow(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1549);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTableDimsOnly([1, 1, 1]),
        );
    }

    /**
     * Rejects ProfileLookTableData count mismatch against dims.
     */
    #[Test]
    public function rejectsLookTableDataCountMismatch(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1554);

        // dims: 1*2*1*3 = 6, but data has 9 floats
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTable([1, 2, 1], 9),
        );
    }

    /**
     * Rejects ProfileLookTableDims present without ProfileLookTableData.
     */
    #[Test]
    public function rejectsLookTableDimsWithoutData(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1551);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTableDimsOnly([1, 2, 1]),
        );
    }

    /**
     * Builds a DNG with ProfileLookTableDims only (no data tag).
     *
     * @param array{0: int, 1: int, 2: int} $dims Hue, saturation, value divisions
     * @param int                           $type TIFF type code for the dims entry
     */
    private function buildDngWithLookTableDimsOnly(array $dims, int $type = TiffConst::TYPE_LONG): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);

        $dimsData = ($type === TiffConst::TYPE_SHORT)
            ? pack('v3', $dims[0], $dims[1], $dims[2])
            : pack('V3', $dims[0], $dims[1], $dims[2]);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_DIMS)
            . pack('v', $type)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData;
    }

    /**
     * Builds a DNG with ProfileLookTableDims and ProfileLookTableData.
     *
     * @param array{0: int, 1: int, 2: int} $dims      Hue, saturation, value divisions
     * @param int                           $dataCount Number of FLOAT values in data tag
     */
    private function buildDngWithLookTable(array $dims, int $dataCount): string
    {
        $ifdOffset         = 8;
        $entryCount        = 7;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);
        $dimsData          = pack('V3', $dims[0], $dims[1], $dims[2]);
        $dataOffset        = $dimsOffset + strlen($dimsData);
        $floatData         = str_repeat(pack('g', 1.0), $dataCount);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_DIMS)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_DATA)
            . pack('v', TiffConst::TYPE_FLOAT)
            . pack('V', $dataCount)
            . pack('V', $dataOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData
            . $floatData;
    }

    /**
     * Builds a 3-IFD DNG where IFD2 is a semantic mask IFD with MaskSubArea.
     *
     * @param int       $maskType  TIFF type code for MaskSubArea entry
     * @param int       $maskCount Count for MaskSubArea entry
     * @param list<int> $maskVals  Values for MaskSubArea
     */
    private function buildDngWithMaskSubArea(int $maskType, int $maskCount, array $maskVals): string
    {
        $ifdOffset         = 8;
        $ifd0Entries       = 5;
        $ifd0Size          = 2 + ($ifd0Entries * 12) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifd0Size;

        $ifd1Offset  = $modelOffset + strlen($uniqueCameraModel);
        $ifd1Entries = 2;
        $ifd1Size    = 2 + ($ifd1Entries * 12) + 4;

        $ifd2Offset  = $ifd1Offset + $ifd1Size;
        $ifd2Entries = 6;
        $ifd2Size    = 2 + ($ifd2Entries * 12) + 4;

        $semanticName   = pack('Z*', 'SkinMask0');
        $nameDataOffset = $ifd2Offset + $ifd2Size;

        // MaskSubArea values stored out-of-line (count >= 2 LONGs = 8+ bytes > 4-byte value field)
        $maskDataOffset = $nameDataOffset + strlen($semanticName);
        $maskData       = '';

        if ($maskType === TiffConst::TYPE_LONG) {
            foreach ($maskVals as $v) {
                $maskData .= pack('V', $v);
            }
        } elseif ($maskType === TiffConst::TYPE_SHORT) {
            foreach ($maskVals as $v) {
                $maskData .= pack('v', $v);
            }
        }

        // IFD0
        $ifd0 = pack('v', $ifd0Entries)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('V', $ifd1Offset);

        // IFD1 (minimal thumbnail)
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

        // IFD2 (semantic mask): tags in ascending order
        // 0x00FE NewSubfileType, 0x0100 ImageWidth, 0x0101 ImageLength,
        // 0x0106 PhotometricInterpretation, 0xCD2E SemanticName, 0xCD38 MaskSubArea
        $ifd2 = pack('v', $ifd2Entries)
            . pack('v', TiffTag::NEW_SUBFILE_TYPE)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', 65540)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 52527) . pack('v', 0)
            . pack('v', DngTag::SEMANTIC_NAME)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($semanticName))
            . pack('V', $nameDataOffset)
            . pack('v', DngTag::MASK_SUB_AREA)
            . pack('v', $maskType)
            . pack('V', $maskCount)
            . pack('V', $maskDataOffset)
            . pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifd0
            . $uniqueCameraModel
            . $ifd1
            . $ifd2
            . $semanticName
            . $maskData;
    }

    /**
     * Accepts valid ActiveArea and two non-overlapping MaskedAreas rectangles.
     */
    #[Test]
    public function acceptsValidActiveAreaAndMaskedAreasRectangles(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::ACTIVE_AREA,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 4,
                    'payload' => pack('V4', 0, 0, 4000, 6000),
                ],
                [
                    'tag'     => DngTag::MASKED_AREAS,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 8,
                    'payload' => pack('V8', 0, 0, 50, 6000, 3950, 0, 4000, 6000),
                ],
            ]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ActiveArea with invalid rectangle ordering.
     */
    #[Test]
    public function rejectsActiveAreaWithInvalidRectangleOrdering(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::ACTIVE_AREA,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 4,
                    'payload' => pack('V4', 100, 0, 100, 200),
                ],
            ]),
        );
    }

    /**
     * Rejects MaskedAreas when count is not divisible by 4.
     */
    #[Test]
    public function rejectsMaskedAreasWithInvalidCountModulo(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::MASKED_AREAS,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 6,
                    'payload' => pack('V6', 0, 0, 50, 6000, 3950, 0),
                ],
            ]),
        );
    }

    /**
     * Rejects MaskedAreas rectangles that overlap.
     */
    #[Test]
    public function rejectsOverlappingMaskedAreasRectangles(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::MASKED_AREAS,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 8,
                    'payload' => pack('V8', 0, 0, 100, 100, 50, 50, 150, 150),
                ],
            ]),
        );
    }

    /**
     * Accepts a fully valid black/white-level family payload set.
     */
    #[Test]
    public function acceptsValidDngBlackWhiteLevelFamily(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily(),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects BlackLevel when its count does not match repeat-dim * samples-per-pixel.
     */
    #[Test]
    public function rejectsBlackLevelCountMismatchAgainstCrossTagFormula(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL => [
                    'count'   => 11,
                    'payload' => str_repeat(pack('V2', 1, 1), 11),
                ],
            ]),
        );
    }

    /**
     * Rejects BlackLevelDeltaH when count does not match ActiveArea width.
     */
    #[Test]
    public function rejectsBlackLevelDeltaHCountMismatchAgainstActiveAreaWidth(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL_DELTA_H => [
                    'count'   => 5,
                    'payload' => str_repeat(pack('V2', 0, 1), 5),
                ],
            ]),
        );
    }

    /**
     * Rejects BlackLevelDeltaV when count does not match ActiveArea length.
     */
    #[Test]
    public function rejectsBlackLevelDeltaVCountMismatchAgainstActiveAreaLength(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL_DELTA_V => [
                    'count'   => 3,
                    'payload' => str_repeat(pack('V2', 0, 1), 3),
                ],
            ]),
        );
    }

    /**
     * Rejects invalid type for BlackLevelRepeatDim.
     */
    #[Test]
    public function rejectsInvalidTypeForBlackLevelRepeatDim(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL_REPEAT_DIM => [
                    'type'    => TiffConst::TYPE_LONG,
                    'payload' => pack('V2', 2, 2),
                ],
            ]),
        );
    }

    /**
     * Rejects invalid type for BlackLevel.
     */
    #[Test]
    public function rejectsInvalidTypeForBlackLevel(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL => [
                    'type'    => TiffConst::TYPE_SRATIONAL,
                    'payload' => str_repeat(pack('V2', 1, 1), 12),
                ],
            ]),
        );
    }

    /**
     * Rejects invalid type for BlackLevelDeltaH.
     */
    #[Test]
    public function rejectsInvalidTypeForBlackLevelDeltaH(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL_DELTA_H => [
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'payload' => str_repeat(pack('V2', 0, 1), 6),
                ],
            ]),
        );
    }

    /**
     * Rejects invalid type for BlackLevelDeltaV.
     */
    #[Test]
    public function rejectsInvalidTypeForBlackLevelDeltaV(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::BLACK_LEVEL_DELTA_V => [
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'payload' => str_repeat(pack('V2', 0, 1), 4),
                ],
            ]),
        );
    }

    /**
     * Rejects invalid type for WhiteLevel.
     */
    #[Test]
    public function rejectsInvalidTypeForWhiteLevel(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBlackWhiteLevelFamily([
                DngTag::WHITE_LEVEL => [
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'payload' => str_repeat(pack('V2', 4095, 1), 3),
                ],
            ]),
        );
    }

    /**
     * Builds a DNG with a valid black/white-level tag family and optional per-tag overrides.
     *
     * @param array<int, array{type?: int, count?: int, payload?: string}> $overrides
     */
    private function buildDngWithBlackWhiteLevelFamily(array $overrides = []): string
    {
        $tags = [
            ExifTag::SAMPLES_PER_PIXEL => [
                'tag'     => ExifTag::SAMPLES_PER_PIXEL,
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 1,
                'payload' => pack('v', 3),
            ],
            DngTag::ACTIVE_AREA => [
                'tag'     => DngTag::ACTIVE_AREA,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 4,
                'payload' => pack('V4', 0, 0, 4, 6),
            ],
            DngTag::BLACK_LEVEL_REPEAT_DIM => [
                'tag'     => DngTag::BLACK_LEVEL_REPEAT_DIM,
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 2,
                'payload' => pack('v2', 2, 2),
            ],
            DngTag::BLACK_LEVEL => [
                'tag'     => DngTag::BLACK_LEVEL,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 12,
                'payload' => str_repeat(pack('V2', 1, 1), 12),
            ],
            DngTag::BLACK_LEVEL_DELTA_H => [
                'tag'     => DngTag::BLACK_LEVEL_DELTA_H,
                'type'    => TiffConst::TYPE_SRATIONAL,
                'count'   => 6,
                'payload' => str_repeat(pack('V2', 0, 1), 6),
            ],
            DngTag::BLACK_LEVEL_DELTA_V => [
                'tag'     => DngTag::BLACK_LEVEL_DELTA_V,
                'type'    => TiffConst::TYPE_SRATIONAL,
                'count'   => 4,
                'payload' => str_repeat(pack('V2', 0, 1), 4),
            ],
            DngTag::WHITE_LEVEL => [
                'tag'     => DngTag::WHITE_LEVEL,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 3,
                'payload' => pack('V3', 4095, 4095, 4095),
            ],
        ];

        foreach ($overrides as $tag => $override) {
            $baseTag = $tags[$tag] ?? [
                'tag'     => $tag,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 1,
                'payload' => pack('V', 1),
            ];

            $tags[$tag] = [
                'tag'     => $tag,
                'type'    => $override['type'] ?? $baseTag['type'],
                'count'   => $override['count'] ?? $baseTag['count'],
                'payload' => $override['payload'] ?? $baseTag['payload'],
            ];
        }

        return $this->buildDngWithDefaultCropScaleTags(array_values($tags));
    }

    /**
     * Accepts ProfileHueSatMapEncoding = 0 (Linear) with 3D dims.
     */
    #[Test]
    public function acceptsHueSatMapEncodingLinear(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithEncoding(DngTag::PROFILE_HUE_SAT_MAP_ENCODING, 0, DngTag::PROFILE_HUE_SAT_MAP_DIMS, [1, 2, 2]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts ProfileLookTableEncoding = 1 (sRGB) with 3D dims and data.
     */
    #[Test]
    public function acceptsLookTableEncodingSrgb(): void
    {
        // dims: 1*2*2*3 = 12 FLOATs
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLookTableAndEncoding([1, 2, 2], 12, 1),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects encoding value outside domain {0, 1}.
     */
    #[Test]
    public function rejectsEncodingOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1556);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithEncoding(DngTag::PROFILE_HUE_SAT_MAP_ENCODING, 2, DngTag::PROFILE_HUE_SAT_MAP_DIMS, [1, 2, 2]),
        );
    }

    /**
     * Rejects encoding tag present with ValueDivisions == 1 (2.5D).
     */
    #[Test]
    public function rejectsEncodingWith25dDims(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1557);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithEncoding(DngTag::PROFILE_HUE_SAT_MAP_ENCODING, 0, DngTag::PROFILE_HUE_SAT_MAP_DIMS, [1, 2, 1]),
        );
    }

    /**
     * Builds a DNG with an encoding tag and its associated dims tag.
     *
     * Tags must appear in ascending tag-ID order in the IFD. The dims tag
     * (0xC6F9 or 0xC725) always comes before the encoding tag (0xC7A3 or 0xC7A4).
     *
     * @param int                           $encTag  Encoding tag constant
     * @param int                           $encVal  Encoding value (0=Linear, 1=sRGB, or invalid)
     * @param int                           $dimsTag Dimensions tag constant
     * @param array{0: int, 1: int, 2: int} $dims    Hue, saturation, value divisions
     */
    private function buildDngWithEncoding(int $encTag, int $encVal, int $dimsTag, array $dims): string
    {
        $ifdOffset         = 8;
        $entryCount        = 7;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);
        $dimsData          = pack('V3', $dims[0], $dims[1], $dims[2]);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $dimsTag)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('v', $encTag)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $encVal)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData;
    }

    /**
     * Builds a DNG with ProfileLookTableDims, ProfileLookTableData, and ProfileLookTableEncoding.
     *
     * @param array{0: int, 1: int, 2: int} $dims      Hue, saturation, value divisions
     * @param int                           $dataCount Number of FLOAT values
     * @param int                           $encVal    Encoding value
     */
    private function buildDngWithLookTableAndEncoding(array $dims, int $dataCount, int $encVal): string
    {
        $ifdOffset         = 8;
        $entryCount        = 8;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dimsOffset        = $modelOffset + strlen($uniqueCameraModel);
        $dimsData          = pack('V3', $dims[0], $dims[1], $dims[2]);
        $dataOffset        = $dimsOffset + strlen($dimsData);
        $floatData         = str_repeat(pack('g', 1.0), $dataCount);

        // Tags in ascending order: 0x0100, 0x0101, 0x0112, 0xC612, 0xC614, 0xC725, 0xC726, 0xC7A4
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_DIMS)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 3)
            . pack('V', $dimsOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_DATA)
            . pack('v', TiffConst::TYPE_FLOAT)
            . pack('V', $dataCount)
            . pack('V', $dataOffset)
            . pack('v', DngTag::PROFILE_LOOK_TABLE_ENCODING)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $encVal)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dimsData
            . $floatData;
    }

    /**
     * Accepts a valid RawImageDigest (BYTE[16]).
     */
    #[Test]
    public function acceptsValidRawImageDigest(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::RAW_IMAGE_DIGEST, TiffConst::TYPE_BYTE, 16),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects RawImageDigest with wrong count.
     */
    #[Test]
    public function rejectsDigestWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1558);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::RAW_IMAGE_DIGEST, TiffConst::TYPE_BYTE, 15),
        );
    }

    /**
     * Rejects NewRawImageDigest with wrong type.
     */
    #[Test]
    public function rejectsDigestWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1558);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::NEW_RAW_IMAGE_DIGEST, TiffConst::TYPE_UNDEFINED, 16),
        );
    }

    /**
     * Accepts a valid PreviewSettingsDigest (BYTE[16]).
     */
    #[Test]
    public function acceptsValidPreviewSettingsDigest(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::PREVIEW_SETTINGS_DIGEST, TiffConst::TYPE_BYTE, 16),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects PreviewSettingsDigest with wrong count.
     */
    #[Test]
    public function rejectsPreviewSettingsDigestWrongCount(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1558);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::PREVIEW_SETTINGS_DIGEST, TiffConst::TYPE_BYTE, 17),
        );
    }

    /**
     * Rejects PreviewSettingsDigest with wrong type.
     */
    #[Test]
    public function rejectsPreviewSettingsDigestWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1558);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDigest(DngTag::PREVIEW_SETTINGS_DIGEST, TiffConst::TYPE_UNDEFINED, 16),
        );
    }

    /**
     * Builds a DNG with a digest tag in IFD0.
     *
     * @param int $digestTag Digest tag constant
     * @param int $type      TIFF type code
     * @param int $count     Component count
     */
    private function buildDngWithDigest(int $digestTag, int $type, int $count): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $digestOffset      = $modelOffset + strlen($uniqueCameraModel);
        $digestData        = str_repeat("\xAB", $count);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $digestTag)
            . pack('v', $type)
            . pack('V', $count)
            . pack('V', $digestOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $digestData;
    }

    /**
     * Accepts valid PreviewColorSpace values 0..4.
     */
    #[Test]
    public function acceptsValidPreviewColorSpace(): void
    {
        foreach (range(0, 4) as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithPreviewColorSpace(TiffConst::TYPE_LONG, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects PreviewColorSpace with out-of-domain value.
     */
    #[Test]
    public function rejectsPreviewColorSpaceOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1560);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewColorSpace(TiffConst::TYPE_LONG, 5),
        );
    }

    /**
     * Rejects PreviewColorSpace with wrong type.
     */
    #[Test]
    public function rejectsPreviewColorSpaceWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1559);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewColorSpace(TiffConst::TYPE_SHORT, 0),
        );
    }

    /**
     * Builds a DNG with a PreviewColorSpace tag in IFD0.
     *
     * @param int $type  TIFF type code
     * @param int $value PreviewColorSpace value
     */
    private function buildDngWithPreviewColorSpace(int $type, int $value): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;

        // PreviewColorSpace 0xC71A > UniqueCameraModel 0xC614 → correct order
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PREVIEW_COLOR_SPACE)
            . pack('v', $type)
            . pack('V', 1)
            . pack('V', $value)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Accepts a valid PreviewDateTime ISO 8601 timestamp.
     */
    #[Test]
    public function acceptsValidPreviewDateTime(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewDateTime("2024-06-15T10:30:00Z\0"),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects PreviewDateTime with malformed ISO 8601 format.
     */
    #[Test]
    public function rejectsPreviewDateTimeMalformedFormat(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1563);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewDateTime("June 15 2024\0"),
        );
    }

    /**
     * Rejects PreviewDateTime with out-of-range month.
     */
    #[Test]
    public function rejectsPreviewDateTimeOverflow(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1564);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewDateTime("2024-13-01T00:00:00\0"),
        );
    }

    /**
     * Builds a DNG with PreviewDateTime (0xC71B) in IFD0.
     *
     * @param string $dateTime NUL-terminated date/time string
     */
    private function buildDngWithPreviewDateTime(string $dateTime): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $dateOffset        = $modelOffset + strlen($uniqueCameraModel);

        // PreviewDateTime 0xC71B > UniqueCameraModel 0xC614 → correct order
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::PREVIEW_DATE_TIME)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($dateTime))
            . pack('V', $dateOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $dateTime;
    }

    /**
     * Accepts a valid DefaultUserCrop RATIONAL[4].
     */
    #[Test]
    public function acceptsValidDefaultUserCrop(): void
    {
        // Top=0/1, Left=0/1, Bottom=1/1, Right=1/1 (full image)
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultUserCrop([[0, 1], [0, 1], [1, 1], [1, 1]]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects DefaultUserCrop with Top >= Bottom.
     */
    #[Test]
    public function rejectsDefaultUserCropTopGteBottom(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1567);

        // Top=1/2, Bottom=1/4 → 0.5 >= 0.25
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultUserCrop([[1, 2], [0, 1], [1, 4], [1, 1]]),
        );
    }

    /**
     * Rejects DefaultUserCrop with out-of-range value.
     */
    #[Test]
    public function rejectsDefaultUserCropOutOfRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1566);

        // Bottom=3/2 = 1.5 (> 1.0)
        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultUserCrop([[0, 1], [0, 1], [3, 2], [1, 1]]),
        );
    }

    /**
     * Builds a DNG with DefaultUserCrop (0xC7B5) RATIONAL[4] in IFD0.
     *
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}, 2: array{0: int, 1: int}, 3: array{0: int, 1: int}} $rationals
     */
    private function buildDngWithDefaultUserCrop(array $rationals): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $cropOffset        = $modelOffset + strlen($uniqueCameraModel);

        $cropData = '';
        foreach ($rationals as [$num, $den]) {
            $cropData .= pack('V', $num) . pack('V', $den);
        }

        // DefaultUserCrop 0xC7B5 > UniqueCameraModel 0xC614 → correct order
        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::DEFAULT_USER_CROP)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 4)
            . pack('V', $cropOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $cropData;
    }

    /**
     * Accepts valid DefaultScale/DefaultCropOrigin/DefaultCropSize layout and values.
     */
    #[Test]
    public function acceptsValidDefaultScaleCropOriginAndCropSize(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_SCALE,
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'count'   => 2,
                    'payload' => pack('V4', 1, 1, 3, 2),
                ],
                [
                    'tag'     => DngTag::DEFAULT_CROP_ORIGIN,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 2,
                    'payload' => pack('V2', 0, 10),
                ],
                [
                    'tag'     => DngTag::DEFAULT_CROP_SIZE,
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'count'   => 2,
                    'payload' => pack('V4', 4000, 1, 3000, 1),
                ],
            ]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects DefaultScale when layout is not RATIONAL[2].
     */
    #[Test]
    public function rejectsDefaultScaleWrongLayout(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_SCALE,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 2,
                    'payload' => pack('V2', 1, 1),
                ],
            ]),
        );
    }

    /**
     * Rejects DefaultCropOrigin when layout is not SHORT|LONG|RATIONAL with count=2.
     */
    #[Test]
    public function rejectsDefaultCropOriginWrongLayout(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_CROP_ORIGIN,
                    'type'    => TiffConst::TYPE_SRATIONAL,
                    'count'   => 2,
                    'payload' => pack('V4', 1, 1, 5, 1),
                ],
            ]),
        );
    }

    /**
     * Rejects DefaultCropSize when layout is not SHORT|LONG|RATIONAL with count=2.
     */
    #[Test]
    public function rejectsDefaultCropSizeWrongLayout(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_CROP_SIZE,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 1,
                    'payload' => pack('V', 100),
                ],
            ]),
        );
    }

    /**
     * Rejects DefaultScale when any component is zero.
     */
    #[Test]
    public function rejectsDefaultScaleWithZeroComponent(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_SCALE,
                    'type'    => TiffConst::TYPE_RATIONAL,
                    'count'   => 2,
                    'payload' => pack('V4', 0, 1, 1, 1),
                ],
            ]),
        );
    }

    /**
     * Rejects DefaultCropSize when any component is not positive.
     */
    #[Test]
    public function rejectsDefaultCropSizeWithNonPositiveValue(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_CROP_SIZE,
                    'type'    => TiffConst::TYPE_LONG,
                    'count'   => 2,
                    'payload' => pack('V2', 0, 1200),
                ],
            ]),
        );
    }

    /**
     * Rejects DefaultCropOrigin when a negative coordinate is encoded.
     */
    #[Test]
    public function rejectsDefaultCropOriginWithNegativeValue(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithDefaultCropScaleTags([
                [
                    'tag'     => DngTag::DEFAULT_CROP_ORIGIN,
                    'type'    => TiffConst::TYPE_SLONG,
                    'count'   => 2,
                    'payload' => pack('V2', 0xFFFFFFFF, 5),
                ],
            ]),
        );
    }

    /**
     * Builds a DNG with crop/scale base tags used by DefaultScale/DefaultCropOrigin/DefaultCropSize tests.
     *
     * @param list<array{tag: int, type: int, count: int, payload: string}> $tags
     */
    private function buildDngWithDefaultCropScaleTags(array $tags): string
    {
        $ifdOffset         = 8;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $baseEntryCount    = 5;
        $entryCount        = $baseEntryCount + count($tags);
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $modelOffset       = $ifdOffset + $ifdSize;

        $entries = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::ORIENTATION => pack('v', ExifTag::ORIENTATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 1) . pack('v', 0),
            DngTag::DNG_VERSION => pack('v', DngTag::DNG_VERSION)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', 4)
                . pack('C4', 1, 7, 1, 0),
            DngTag::UNIQUE_CAMERA_MODEL => pack('v', DngTag::UNIQUE_CAMERA_MODEL)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($uniqueCameraModel))
                . pack('V', $modelOffset),
        ];

        $outOfLineData   = $uniqueCameraModel;
        $nextValueOffset = $modelOffset + strlen($uniqueCameraModel);

        if ($nextValueOffset % 2 !== 0) {
            $outOfLineData .= "\0";
            ++$nextValueOffset;
        }

        foreach ($tags as $tagSpec) {
            $tag       = $tagSpec['tag'];
            $type      = $tagSpec['type'];
            $count     = $tagSpec['count'];
            $payload   = $tagSpec['payload'];
            $valueSize = $this->bytesPerTiffTypeForTest($type) * $count;

            if ($valueSize <= 4) {
                $valueField = str_pad(substr($payload, 0, $valueSize), 4, "\0");
            } else {
                if ($nextValueOffset % 2 !== 0) {
                    $outOfLineData .= "\0";
                    ++$nextValueOffset;
                }

                $valueField = pack('V', $nextValueOffset);
                $outOfLineData .= substr($payload, 0, $valueSize);
                $nextValueOffset += $valueSize;
            }

            $entries[$tag] = pack('v', $tag)
                . pack('v', $type)
                . pack('V', $count)
                . $valueField;
        }

        ksort($entries);

        $ifdData = pack('v', $entryCount);
        foreach ($entries as $entry) {
            $ifdData .= $entry;
        }

        $ifdData .= pack('V', 0);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . $ifdData
            . $outOfLineData;
    }

    private function bytesPerTiffTypeForTest(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT     => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            default                   => throw new InvalidArgumentException('Unsupported TIFF type for crop/scale test builder.'),
        };
    }

    /**
     * Accepts DefaultBlackRender values 0 and 1.
     */
    #[Test]
    public function acceptsValidDefaultBlackRender(): void
    {
        foreach ([0, 1] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithLong1Tag(DngTag::DEFAULT_BLACK_RENDER, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects DefaultBlackRender with out-of-domain value.
     */
    #[Test]
    public function rejectsDefaultBlackRenderOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1570);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLong1Tag(DngTag::DEFAULT_BLACK_RENDER, 2),
        );
    }

    /**
     * Builds a DNG with a LONG[1] tag inline in IFD0.
     *
     * @param int $tag   Tag constant (must be > 0xC614)
     * @param int $value Tag value
     */
    /**
     * Accepts PreviewApplicationName as ASCII NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsPreviewStringTagAscii(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_APPLICATION_NAME,
                TiffConst::TYPE_ASCII,
                "TestApp\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts PreviewApplicationVersion as BYTE NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsPreviewStringTagByte(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_APPLICATION_VERSION,
                TiffConst::TYPE_BYTE,
                "1.0\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts PreviewSettingsName as ASCII with UTF-8 content.
     */
    #[Test]
    public function acceptsPreviewStringTagUtf8(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_SETTINGS_NAME,
                TiffConst::TYPE_ASCII,
                "Schärfe\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects preview string tag with wrong type (SHORT).
     */
    #[Test]
    public function rejectsPreviewStringTagWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1571);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_APPLICATION_NAME,
                TiffConst::TYPE_SHORT,
                "\x00\x01",
            ),
        );
    }

    /**
     * Rejects BYTE preview string tag missing trailing NUL.
     */
    #[Test]
    public function rejectsPreviewStringTagMissingNul(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1572);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_APPLICATION_VERSION,
                TiffConst::TYPE_BYTE,
                'NoNul',
            ),
        );
    }

    /**
     * Rejects BYTE preview string tag with invalid UTF-8.
     */
    #[Test]
    public function rejectsPreviewStringTagInvalidUtf8Byte(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1573);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PREVIEW_SETTINGS_NAME,
                TiffConst::TYPE_BYTE,
                "\xC0\xAF\0",
            ),
        );
    }

    /**
     * Accepts ProfileEmbedPolicy values 0..3.
     */
    #[Test]
    public function acceptsValidProfileEmbedPolicy(): void
    {
        foreach ([0, 1, 2, 3] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithLong1Tag(DngTag::PROFILE_EMBED_POLICY, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects ProfileEmbedPolicy with out-of-domain value.
     */
    #[Test]
    public function rejectsProfileEmbedPolicyOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1583);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithLong1Tag(DngTag::PROFILE_EMBED_POLICY, 4),
        );
    }

    /**
     * Accepts CFALayout values 1..5 (no version gating).
     */
    #[Test]
    public function acceptsValidCfaLayoutValues(): void
    {
        foreach ([1, 2, 3, 4, 5] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithShort1Tag(DngTag::CFA_LAYOUT, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects CFALayout with out-of-domain value (0).
     */
    #[Test]
    public function rejectsCfaLayoutOutOfDomainZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1584);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::CFA_LAYOUT, 0),
        );
    }

    /**
     * Rejects CFALayout with out-of-domain value (10).
     */
    #[Test]
    public function rejectsCfaLayoutOutOfDomainTen(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1584);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::CFA_LAYOUT, 10),
        );
    }

    /**
     * Accepts CFALayout value 8 with DNGBackwardVersion 1.3.0.0.
     */
    #[Test]
    public function acceptsCfaLayout8WithVersion130(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithCfaLayoutAndBackwardVersion(8, [1, 3, 0, 0]),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects CFALayout value 8 with DNGBackwardVersion 1.2.0.0.
     */
    #[Test]
    public function rejectsCfaLayout8WithVersion120(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1585);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithCfaLayoutAndBackwardVersion(8, [1, 2, 0, 0]),
        );
    }

    /**
     * Builds a DNG with CFALayout and DNGBackwardVersion in IFD0.
     *
     * @param int       $cfaLayout       CFALayout value
     * @param list<int> $backwardVersion DNGBackwardVersion (4 bytes)
     */
    private function buildDngWithCfaLayoutAndBackwardVersion(int $cfaLayout, array $backwardVersion): string
    {
        $ifdOffset         = 8;
        $entryCount        = 7;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::DNG_BACKWARD_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', $backwardVersion[0], $backwardVersion[1], $backwardVersion[2], $backwardVersion[3])
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::CFA_LAYOUT)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $cfaLayout) . pack('v', 0)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Accepts ProfileCopyright as ASCII NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsValidProfileCopyright(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_COPYRIGHT,
                TiffConst::TYPE_ASCII,
                "Copyright 2024\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts ProfileCopyright as BYTE NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsValidProfileCopyrightByte(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_COPYRIGHT,
                TiffConst::TYPE_BYTE,
                "\xC2\xA9 2024\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects ProfileCopyright with wrong type.
     */
    #[Test]
    public function rejectsProfileCopyrightWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1571);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_COPYRIGHT,
                TiffConst::TYPE_SHORT,
                "\x00\x01",
            ),
        );
    }

    /**
     * Rejects BYTE ProfileCopyright missing trailing NUL.
     */
    #[Test]
    public function rejectsProfileCopyrightMissingNul(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1572);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_COPYRIGHT,
                TiffConst::TYPE_BYTE,
                'NoNul',
            ),
        );
    }

    /**
     * Rejects BYTE ProfileCopyright with invalid UTF-8.
     */
    #[Test]
    public function rejectsProfileCopyrightInvalidUtf8(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1573);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_COPYRIGHT,
                TiffConst::TYPE_BYTE,
                "\xC0\xAF\0",
            ),
        );
    }

    /**
     * Accepts CameraCalibrationSignature as ASCII NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsValidCameraCalibrationSignature(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::CAMERA_CALIBRATION_SIGNATURE,
                TiffConst::TYPE_ASCII,
                "com.adobe\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts ProfileCalibrationSignature as BYTE NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsValidProfileCalibrationSignatureByte(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_CALIBRATION_SIGNATURE,
                TiffConst::TYPE_BYTE,
                "ACR 4.4\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Accepts AsShotProfileName as ASCII with UTF-8 content.
     */
    #[Test]
    public function acceptsValidAsShotProfileNameUtf8(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::AS_SHOT_PROFILE_NAME,
                TiffConst::TYPE_ASCII,
                "Porträt\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects profile signature tag with wrong type.
     */
    #[Test]
    public function rejectsProfileSignatureWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1571);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::CAMERA_CALIBRATION_SIGNATURE,
                TiffConst::TYPE_SHORT,
                "\x00\x01",
            ),
        );
    }

    /**
     * Rejects BYTE profile signature tag missing trailing NUL.
     */
    #[Test]
    public function rejectsProfileSignatureMissingNul(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1572);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::AS_SHOT_PROFILE_NAME,
                TiffConst::TYPE_BYTE,
                'NoNul',
            ),
        );
    }

    /**
     * Rejects BYTE profile signature tag with invalid UTF-8.
     */
    #[Test]
    public function rejectsProfileSignatureInvalidUtf8(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1573);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::PROFILE_CALIBRATION_SIGNATURE,
                TiffConst::TYPE_BYTE,
                "\xC0\xAF\0",
            ),
        );
    }

    /**
     * Accepts OriginalRawFileName as NUL-terminated UTF-8.
     */
    #[Test]
    public function acceptsValidOriginalRawFileName(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ORIGINAL_RAW_FILE_NAME,
                TiffConst::TYPE_ASCII,
                "IMG_0001.CR2\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects OriginalRawFileName when BYTE payload is not NUL-terminated.
     */
    #[Test]
    public function rejectsOriginalRawFileNameMissingNul(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1572);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ORIGINAL_RAW_FILE_NAME,
                TiffConst::TYPE_BYTE,
                'IMG_0001.CR2',
            ),
        );
    }

    /**
     * Rejects OriginalRawFileName when UTF-8 payload is malformed.
     */
    #[Test]
    public function rejectsOriginalRawFileNameInvalidUtf8(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1573);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ORIGINAL_RAW_FILE_NAME,
                TiffConst::TYPE_BYTE,
                "\xC0\xAF\0",
            ),
        );
    }

    /**
     * Rejects OriginalRawFileData when type is not UNDEFINED.
     */
    #[Test]
    public function rejectsOriginalRawFileDataWrongType(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithOriginalRawFileData(
                TiffConst::TYPE_LONG,
                'A',
            ),
        );
    }

    /**
     * Rejects OriginalRawFileData when the documented block sequence is truncated.
     */
    #[Test]
    public function rejectsOriginalRawFileDataTruncatedBlockSequence(): void
    {
        $this->expectException(ParseError::class);

        $payload = $this->buildMinimalOriginalRawFileDataPayload();

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithOriginalRawFileData(
                TiffConst::TYPE_UNDEFINED,
                substr($payload, 0, strlen($payload) - 4),
            ),
        );
    }

    /**
     * Accepts OriginalRawFileData with valid blocks and extra trailing bytes.
     */
    #[Test]
    public function acceptsOriginalRawFileDataWithExtraTrailingBytes(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithOriginalRawFileData(
                TiffConst::TYPE_UNDEFINED,
                $this->buildMinimalOriginalRawFileDataPayload() . "\xDE\xAD\xBE\xEF",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    private function buildDngWithOriginalRawFileData(int $type, string $payload): string
    {
        return $this->buildDngWithDefaultCropScaleTags([
            [
                'tag'     => DngTag::ORIGINAL_RAW_FILE_DATA,
                'type'    => $type,
                'count'   => strlen($payload),
                'payload' => $payload,
            ],
        ]);
    }

    private function buildMinimalOriginalRawFileDataPayload(): string
    {
        return str_repeat(pack('N', 0), 8);
    }

    /**
     * Accepts a valid EnhanceParams NUL-terminated ASCII string.
     */
    #[Test]
    public function acceptsValidEnhanceParams(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ENHANCE_PARAMS,
                TiffConst::TYPE_ASCII,
                "Adobe SuperResolution v1\0",
            ),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects EnhanceParams with wrong type (SHORT).
     */
    #[Test]
    public function rejectsEnhanceParamsWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1575);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ENHANCE_PARAMS,
                TiffConst::TYPE_SHORT,
                "\x00\x01",
            ),
        );
    }

    /**
     * Rejects EnhanceParams with empty content (only NUL terminator).
     */
    #[Test]
    public function rejectsEnhanceParamsEmpty(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1576);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithPreviewStringTag(
                DngTag::ENHANCE_PARAMS,
                TiffConst::TYPE_ASCII,
                "\0",
            ),
        );
    }

    /**
     * Accepts SubTileBlockSize with valid SHORT[2] values.
     */
    #[Test]
    public function acceptsValidSubTileBlockSize(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSubTileBlockSize(TiffConst::TYPE_SHORT, 2, 2),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects SubTileBlockSize with wrong type (RATIONAL).
     */
    #[Test]
    public function rejectsSubTileBlockSizeWrongType(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1577);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSubTileBlockSize(TiffConst::TYPE_BYTE, 1, 1),
        );
    }

    /**
     * Rejects SubTileBlockSize with zero component.
     */
    #[Test]
    public function rejectsSubTileBlockSizeZeroComponent(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1578);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSubTileBlockSize(TiffConst::TYPE_SHORT, 0, 1),
        );
    }

    /**
     * Accepts RowInterleaveFactor with valid SHORT[1] value.
     */
    #[Test]
    public function acceptsValidRowInterleaveFactor(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::ROW_INTERLEAVE_FACTOR, 2),
        );

        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects RowInterleaveFactor with zero value.
     */
    #[Test]
    public function rejectsRowInterleaveFactorZero(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1580);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::ROW_INTERLEAVE_FACTOR, 0),
        );
    }

    /**
     * Builds a DNG with SubTileBlockSize in IFD0.
     *
     * @param int $type TIFF type code (SHORT or LONG)
     * @param int $rows SubTileBlockRows
     * @param int $cols SubTileBlockCols
     */
    private function buildDngWithSubTileBlockSize(int $type, int $rows, int $cols): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;

        if ($type === TiffConst::TYPE_SHORT) {
            $valueBytes = pack('v', $rows) . pack('v', $cols);
        } elseif ($type === TiffConst::TYPE_LONG) {
            $valueBytes = pack('V', $rows) . pack('V', $cols);
        } else {
            // For wrong-type test: use 2 bytes (BYTE count=2)
            $valueBytes = pack('C', $rows) . pack('C', $cols) . "\x00\x00";
        }

        $count     = 2;
        $dataSize  = $this->bytesPerComponent($type) * $count;
        $inline    = $dataSize <= 4;
        $tagOffset = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::SUB_TILE_BLOCK_SIZE)
            . pack('v', $type)
            . pack('V', $count)
            . ($inline
                ? str_pad($valueBytes, 4, "\0")
                : pack('V', $tagOffset))
            . pack('V', 0)
            . $uniqueCameraModel
            . ($inline ? '' : $valueBytes);
    }

    private function bytesPerComponent(int $type): int
    {
        return match ($type) {
            TiffConst::TYPE_ASCII,
            TiffConst::TYPE_BYTE,
            TiffConst::TYPE_UNDEFINED => 1,
            TiffConst::TYPE_SHORT     => 2,
            TiffConst::TYPE_LONG,
            TiffConst::TYPE_SLONG => 4,
            TiffConst::TYPE_RATIONAL,
            TiffConst::TYPE_SRATIONAL => 8,
            TiffConst::TYPE_DOUBLE    => 8,
            default                   => 1,
        };
    }

    /**
     * Accepts NoiseReductionApplied with valid RATIONAL[1] values.
     */
    #[Test]
    public function acceptsValidNoiseReductionApplied(): void
    {
        // 0/0 sentinel (unknown)
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRational1Tag(DngTag::NOISE_REDUCTION_APPLIED, 0, 0),
        );
        self::assertSame('1.7.1.0', $parsed->dngVersion());

        // 1/2 (0.5)
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRational1Tag(DngTag::NOISE_REDUCTION_APPLIED, 1, 2),
        );
        self::assertSame('1.7.1.0', $parsed->dngVersion());

        // 1/1 (1.0 upper bound)
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRational1Tag(DngTag::NOISE_REDUCTION_APPLIED, 1, 1),
        );
        self::assertSame('1.7.1.0', $parsed->dngVersion());
    }

    /**
     * Rejects NoiseReductionApplied with value above 1.0.
     */
    #[Test]
    public function rejectsNoiseReductionAppliedAboveRange(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1582);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRational1Tag(DngTag::NOISE_REDUCTION_APPLIED, 3, 2),
        );
    }

    /**
     * Rejects NoiseReductionApplied with non-sentinel zero denominator.
     */
    #[Test]
    public function rejectsNoiseReductionAppliedZeroDenominator(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1581);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithRational1Tag(DngTag::NOISE_REDUCTION_APPLIED, 1, 0),
        );
    }

    /**
     * Builds a DNG with a RATIONAL[1] tag in IFD0.
     *
     * @param int $tag         Tag constant (must be > 0xC614)
     * @param int $numerator   Rational numerator
     * @param int $denominator Rational denominator
     */
    private function buildDngWithRational1Tag(int $tag, int $numerator, int $denominator): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $rationalData      = pack('V', $numerator) . pack('V', $denominator);
        $rationalOffset    = $modelOffset + strlen($uniqueCameraModel);

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_RATIONAL)
            . pack('V', 1)
            . pack('V', $rationalOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $rationalData;
    }

    /**
     * Accepts valid DepthFormat enum values (0, 1, 2).
     */
    #[Test]
    public function acceptsValidDepthFormatValues(): void
    {
        foreach ([0, 1, 2] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithShort1Tag(DngTag::DEPTH_FORMAT, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects DepthFormat with out-of-domain value.
     */
    #[Test]
    public function rejectsDepthFormatOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1574);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::DEPTH_FORMAT, 3),
        );
    }

    /**
     * Accepts valid DepthUnits enum values (0, 1).
     */
    #[Test]
    public function acceptsValidDepthUnitsValues(): void
    {
        foreach ([0, 1] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithShort1Tag(DngTag::DEPTH_UNITS, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects DepthUnits with out-of-domain value.
     */
    #[Test]
    public function rejectsDepthUnitsOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1574);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::DEPTH_UNITS, 2),
        );
    }

    /**
     * Accepts valid DepthMeasureType enum values (0, 1, 2).
     */
    #[Test]
    public function acceptsValidDepthMeasureTypeValues(): void
    {
        foreach ([0, 1, 2] as $value) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithShort1Tag(DngTag::DEPTH_MEASURE_TYPE, $value),
            );

            self::assertSame('1.7.1.0', $parsed->dngVersion());
        }
    }

    /**
     * Rejects DepthMeasureType with out-of-domain value.
     */
    #[Test]
    public function rejectsDepthMeasureTypeOutOfDomain(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1574);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithShort1Tag(DngTag::DEPTH_MEASURE_TYPE, 3),
        );
    }

    /**
     * Accepts a valid ExtraCameraProfiles payload with one embedded camera profile header.
     */
    #[Test]
    public function acceptsValidExtraCameraProfilesPayload(): void
    {
        $profilePayload = 'II'
            . pack('v', 0x4352)
            . pack('V', 8)
            . pack('v', 0)
            . pack('V', 0);

        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithExtraCameraProfiles($profilePayload),
        );

        $entry = $parsed->ifd0->get(DngTag::EXTRA_CAMERA_PROFILES);
        self::assertNotNull($entry);
    }

    /**
     * Rejects ExtraCameraProfiles entries whose referenced profile offset is out of blob range.
     */
    #[Test]
    public function rejectsExtraCameraProfilesWithOutOfRangeOffset(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithExtraCameraProfiles('', profileOffsetOverride: 0x7FFFFFF0),
        );
    }

    /**
     * Rejects ExtraCameraProfiles entries with a bad camera-profile magic marker.
     */
    #[Test]
    public function rejectsExtraCameraProfilesWithBadProfileMagic(): void
    {
        $this->expectException(ParseError::class);

        $profilePayload = 'II'
            . pack('v', 0x1234)
            . pack('V', 8)
            . pack('v', 0)
            . pack('V', 0);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithExtraCameraProfiles($profilePayload),
        );
    }

    /**
     * Rejects ExtraCameraProfiles entries whose inner IFD offset is outside the profile payload range.
     */
    #[Test]
    public function rejectsExtraCameraProfilesWithInvalidInnerIfdOffset(): void
    {
        $this->expectException(ParseError::class);

        $profilePayload = 'II'
            . pack('v', 0x4352)
            . pack('V', 0x7FFFFFF0);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithExtraCameraProfiles($profilePayload),
        );
    }

    /**
     * Rejects ExtraCameraProfiles entries when the referenced profile header is truncated.
     */
    #[Test]
    public function rejectsExtraCameraProfilesWithTruncatedProfileHeader(): void
    {
        $this->expectException(ParseError::class);

        $profilePayload = 'II' . pack('v', 0x4352);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithExtraCameraProfiles($profilePayload),
        );
    }

    /**
     * DNG 1.7.1.0 Chapter 7: Opcode lists may be empty.
     *
     * Ensures OpcodeList1/2/3 accept the canonical empty payload (count = 0).
     */
    #[Test]
    public function parsesEmptyOpcodeListsForAllOpcodeTags(): void
    {
        foreach (self::DNG_OPCODE_LIST_TAGS as $tag) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithOpcodeListTag($tag, pack('N', 0)),
            );

            self::assertNotNull($parsed->ifd0->get($tag));
        }
    }

    /**
     * DNG 1.7.1.0 Chapter 7: opcode list entries use big-endian framing.
     *
     * Verifies a one-opcode list (unknown ID with skippable payload) parses for
     * each opcode-list tag.
     */
    #[Test]
    public function parsesWellFormedSingleOpcodeForAllOpcodeTags(): void
    {
        $payload = pack('N', 1)
            . pack('N', 0x7FFFFFFF)
            . pack('N', 0x01030000)
            . pack('N', 0)
            . pack('N', 3)
            . 'abc';

        foreach (self::DNG_OPCODE_LIST_TAGS as $tag) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithOpcodeListTag($tag, $payload),
            );

            self::assertNotNull($parsed->ifd0->get($tag));
        }
    }

    /**
     * DNG 1.7.1.0 Chapter 7: opcode records require complete fixed-size headers.
     *
     * A list declaring one opcode but truncating the opcode header must fail.
     */
    #[Test]
    public function rejectsOpcodeListWithTruncatedOpcodeHeaderForAllOpcodeTags(): void
    {
        $payload = pack('N', 1)
            . pack('N', 1)
            . pack('N', 0x01030000);
        $rejections = 0;

        foreach (self::DNG_OPCODE_LIST_TAGS as $tag) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOpcodeListTag($tag, $payload),
                );
                self::fail(
                    sprintf('Expected ParseError for truncated opcode header in tag 0x%04X.', $tag),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count(self::DNG_OPCODE_LIST_TAGS), $rejections);
    }

    /**
     * DNG 1.7.1.0 Chapter 7: opcode payload length must fit in remaining bytes.
     *
     * A record whose parameter byte count exceeds available bytes must fail.
     */
    #[Test]
    public function rejectsOpcodeListWithOverflowingOpcodePayloadForAllOpcodeTags(): void
    {
        $payload = pack('N', 1)
            . pack('N', 1)
            . pack('N', 0x01030000)
            . pack('N', 0)
            . pack('N', 8)
            . 'abc';
        $rejections = 0;

        foreach (self::DNG_OPCODE_LIST_TAGS as $tag) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOpcodeListTag($tag, $payload),
                );
                self::fail(
                    sprintf('Expected ParseError for overflowing opcode payload in tag 0x%04X.', $tag),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count(self::DNG_OPCODE_LIST_TAGS), $rejections);
    }

    /**
     * DNG 1.7.1.0 defines OpcodeList1/2/3 as UNDEFINED type tags.
     */
    #[Test]
    public function rejectsOpcodeListWithNonUndefinedType(): void
    {
        $rejections = 0;

        foreach (self::DNG_OPCODE_LIST_TAGS as $tag) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOpcodeListTag($tag, pack('N', 0), TiffConst::TYPE_LONG),
                );
                self::fail(
                    sprintf('Expected ParseError for non-UNDEFINED opcode-list type in tag 0x%04X.', $tag),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count(self::DNG_OPCODE_LIST_TAGS), $rejections);
    }

    /**
     * Validates that each original proxy-size tag accepts its allowed type/count layout.
     */
    #[Test]
    public function parsesValidOriginalProxySizeTags(): void
    {
        $cases = [
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 2,
                'payload' => pack('v2', 4000, 3000),
            ],
            [
                'tag'     => DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 2,
                'payload' => pack('V2', 4000, 3000),
            ],
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 4000, 1, 3000, 1),
            ],
        ];

        foreach ($cases as $case) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithOriginalProxySizeTag(
                    $case['tag'],
                    $case['type'],
                    $case['count'],
                    $case['payload'],
                ),
            );

            self::assertNotNull($parsed->ifd0->get($case['tag']));
        }
    }

    /**
     * Rejects invalid type domains for original proxy-size tags.
     */
    #[Test]
    public function rejectsOriginalProxySizeTagsWithWrongType(): void
    {
        $cases = [
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 4000, 1, 3000, 1),
            ],
            [
                'tag'     => DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
                'type'    => TiffConst::TYPE_ASCII,
                'count'   => 2,
                'payload' => "X\0",
            ],
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
                'type'    => TiffConst::TYPE_BYTE,
                'count'   => 2,
                'payload' => "\x01\x01",
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOriginalProxySizeTag(
                        $case['tag'],
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid type on proxy-size tag 0x%04X.', $case['tag']),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * Rejects invalid counts for original proxy-size tags.
     */
    #[Test]
    public function rejectsOriginalProxySizeTagsWithWrongCount(): void
    {
        $cases = [
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 1,
                'payload' => pack('v', 4000),
            ],
            [
                'tag'     => DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 3,
                'payload' => pack('V3', 4000, 3000, 1000),
            ],
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 1,
                'payload' => pack('V2', 4000, 1),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOriginalProxySizeTag(
                        $case['tag'],
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid count on proxy-size tag 0x%04X.', $case['tag']),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * Rejects non-positive (or equivalent invalid rational) original proxy-size dimensions.
     */
    #[Test]
    public function rejectsOriginalProxySizeTagsWithNonPositiveDimensions(): void
    {
        $cases = [
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_FINAL_SIZE,
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 2,
                'payload' => pack('v2', 0, 3000),
            ],
            [
                'tag'     => DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 2,
                'payload' => pack('V2', 4000, 0),
            ],
            [
                'tag'     => DngTag::ORIGINAL_DEFAULT_CROP_SIZE,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 4000, 0, 3000, 1),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithOriginalProxySizeTag(
                        $case['tag'],
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid dimensions on proxy-size tag 0x%04X.', $case['tag']),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * Regression: missing OriginalDefaultFinalSize must not break fallback handling.
     *
     * OriginalBestQualityFinalSize may still be present and valid; readers should
     * use documented fallback defaults for omitted proxy tags.
     */
    #[Test]
    public function acceptsProxySizeTagsWhenDependentProxyTagsAreMissing(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithOriginalProxySizeTag(
                DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE,
                TiffConst::TYPE_LONG,
                2,
                pack('V2', 5000, 3500),
            ),
        );

        self::assertNull($parsed->ifd0->get(DngTag::ORIGINAL_DEFAULT_FINAL_SIZE));
        self::assertNotNull($parsed->ifd0->get(DngTag::ORIGINAL_BEST_QUALITY_FINAL_SIZE));
        self::assertNull($parsed->ifd0->get(DngTag::ORIGINAL_DEFAULT_CROP_SIZE));
    }

    /**
     * BestQualityScale accepts a positive RATIONAL[1] payload.
     */
    #[Test]
    public function parsesValidBestQualityScale(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::BEST_QUALITY_SCALE,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 2, 1),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::BEST_QUALITY_SCALE));
    }

    /**
     * BestQualityScale rejects wrong type/count layouts.
     */
    #[Test]
    public function rejectsBestQualityScaleWithWrongTypeOrCount(): void
    {
        $cases = [
            [
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 1,
                'payload' => pack('V', 1),
            ],
            [
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 1, 1, 1, 1),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        DngTag::BEST_QUALITY_SCALE,
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail('Expected ParseError for invalid BestQualityScale type/count.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * BestQualityScale rejects non-positive values.
     */
    #[Test]
    public function rejectsBestQualityScaleWithNonPositiveValue(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::BEST_QUALITY_SCALE,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 0, 1),
            ),
        );
    }

    /**
     * LinearResponseLimit accepts a positive fractional RATIONAL[1] value.
     */
    #[Test]
    public function parsesValidLinearResponseLimit(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LINEAR_RESPONSE_LIMIT,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 3, 4),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::LINEAR_RESPONSE_LIMIT));
    }

    /**
     * LinearResponseLimit rejects wrong type/count layouts.
     */
    #[Test]
    public function rejectsLinearResponseLimitWithWrongTypeOrCount(): void
    {
        $cases = [
            [
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 1,
                'payload' => pack('V', 1),
            ],
            [
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 1, 1, 1, 1),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        DngTag::LINEAR_RESPONSE_LIMIT,
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail('Expected ParseError for invalid LinearResponseLimit type/count.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * LinearResponseLimit rejects values <= 0.
     */
    #[Test]
    public function rejectsLinearResponseLimitWithNonPositiveValue(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LINEAR_RESPONSE_LIMIT,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 0, 1),
            ),
        );
    }

    /**
     * LinearResponseLimit rejects values above 1.0.
     */
    #[Test]
    public function rejectsLinearResponseLimitAboveOne(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LINEAR_RESPONSE_LIMIT,
                TiffConst::TYPE_RATIONAL,
                1,
                pack('V2', 5, 4),
            ),
        );
    }

    /**
     * LensInfo accepts a valid RATIONAL[4] layout.
     */
    #[Test]
    public function parsesValidLensInfo(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LENS_INFO,
                TiffConst::TYPE_RATIONAL,
                4,
                pack('V8', 24, 1, 70, 1, 28, 10, 40, 10),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::LENS_INFO));
    }

    /**
     * LensInfo rejects wrong type/count.
     */
    #[Test]
    public function rejectsLensInfoWithWrongTypeOrCount(): void
    {
        $cases = [
            [
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 4,
                'payload' => pack('V4', 24, 70, 28, 40),
            ],
            [
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 3,
                'payload' => pack('V6', 24, 1, 70, 1, 28, 10),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        DngTag::LENS_INFO,
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail('Expected ParseError for invalid LensInfo type/count.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * LensInfo rejects focal-length ordering inversions.
     */
    #[Test]
    public function rejectsLensInfoWhenMinimumFocalExceedsMaximum(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LENS_INFO,
                TiffConst::TYPE_RATIONAL,
                4,
                pack('V8', 70, 1, 24, 1, 28, 10, 40, 10),
            ),
        );
    }

    /**
     * LensInfo allows 0/0 aperture sentinel values for unknown min f-stop fields.
     */
    #[Test]
    public function acceptsLensInfoApertureUnknownSentinel(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithSingleCustomTag(
                DngTag::LENS_INFO,
                TiffConst::TYPE_RATIONAL,
                4,
                pack('V8', 24, 1, 70, 1, 0, 0, 0, 0),
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::LENS_INFO));
    }

    /**
     * LensInfo rejects zero-denominator values outside allowed aperture 0/0 sentinels.
     */
    #[Test]
    public function rejectsLensInfoWithInvalidZeroDenominator(): void
    {
        $cases = [
            pack('V8', 24, 0, 70, 1, 28, 10, 40, 10),
            pack('V8', 24, 1, 70, 1, 1, 0, 40, 10),
        ];
        $rejections = 0;

        foreach ($cases as $payload) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        DngTag::LENS_INFO,
                        TiffConst::TYPE_RATIONAL,
                        4,
                        $payload,
                    ),
                );
                self::fail('Expected ParseError for invalid LensInfo denominator-zero usage.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * BaselineNoise and BaselineSharpness accept positive RATIONAL[1] values.
     */
    #[Test]
    public function parsesValidBaselineNoiseAndSharpness(): void
    {
        foreach ([DngTag::BASELINE_NOISE, DngTag::BASELINE_SHARPNESS] as $tag) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithSingleCustomTag(
                    $tag,
                    TiffConst::TYPE_RATIONAL,
                    1,
                    pack('V2', 3, 2),
                ),
            );

            self::assertNotNull($parsed->ifd0->get($tag));
        }
    }

    /**
     * BaselineNoise and BaselineSharpness reject wrong type/count layouts.
     */
    #[Test]
    public function rejectsBaselineNoiseAndSharpnessWithWrongTypeOrCount(): void
    {
        $cases = [
            [
                'tag'     => DngTag::BASELINE_NOISE,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 1,
                'payload' => pack('V', 1),
            ],
            [
                'tag'     => DngTag::BASELINE_NOISE,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 1, 1, 1, 1),
            ],
            [
                'tag'     => DngTag::BASELINE_SHARPNESS,
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 1,
                'payload' => pack('V', 1),
            ],
            [
                'tag'     => DngTag::BASELINE_SHARPNESS,
                'type'    => TiffConst::TYPE_RATIONAL,
                'count'   => 2,
                'payload' => pack('V4', 1, 1, 1, 1),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        $case['tag'],
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid baseline scalar layout in tag 0x%04X.', $case['tag']),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * BaselineNoise and BaselineSharpness reject zero and non-finite rational values.
     */
    #[Test]
    public function rejectsBaselineNoiseAndSharpnessWithInvalidValues(): void
    {
        $cases = [
            [
                'tag'     => DngTag::BASELINE_NOISE,
                'payload' => pack('V2', 0, 1),
            ],
            [
                'tag'     => DngTag::BASELINE_NOISE,
                'payload' => pack('V2', 1, 0),
            ],
            [
                'tag'     => DngTag::BASELINE_SHARPNESS,
                'payload' => pack('V2', 0, 1),
            ],
            [
                'tag'     => DngTag::BASELINE_SHARPNESS,
                'payload' => pack('V2', 1, 0),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        $case['tag'],
                        TiffConst::TYPE_RATIONAL,
                        1,
                        $case['payload'],
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid baseline scalar value in tag 0x%04X.', $case['tag']),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * AnalogBalance accepts RATIONAL[ColorPlanes] with positive finite gains.
     */
    #[Test]
    public function parsesValidAnalogBalanceLayout(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::ANALOG_BALANCE,
                TiffConst::TYPE_RATIONAL,
                3,
                3,
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::ANALOG_BALANCE));
    }

    /**
     * AnalogBalance rejects wrong field type.
     */
    #[Test]
    public function rejectsAnalogBalanceWithWrongType(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::ANALOG_BALANCE,
                TiffConst::TYPE_SHORT,
                3,
                3,
            ),
        );
    }

    /**
     * AnalogBalance rejects count mismatches against ColorPlanes.
     */
    #[Test]
    public function rejectsAnalogBalanceWithWrongCount(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildTiffWithWhiteBalanceLayout(
                DngTag::ANALOG_BALANCE,
                TiffConst::TYPE_RATIONAL,
                2,
                3,
            ),
        );
    }

    /**
     * AnalogBalance rejects non-positive and non-finite gain components.
     */
    #[Test]
    public function rejectsAnalogBalanceWithInvalidGainValues(): void
    {
        $cases = [
            pack('V6', 1, 1, 0, 1, 1, 1),
            pack('V6', 1, 1, 1, 0, 1, 1),
        ];
        $rejections = 0;

        foreach ($cases as $payload) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildTiffWithWhiteBalanceLayout(
                        DngTag::ANALOG_BALANCE,
                        TiffConst::TYPE_RATIONAL,
                        3,
                        3,
                        $payload,
                    ),
                );
                self::fail('Expected ParseError for invalid AnalogBalance gain vector.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * BayerGreenSplit accepts LONG[1] with non-negative value in Bayer CFA context.
     */
    #[Test]
    public function parsesValidBayerGreenSplitInBayerContext(): void
    {
        $parsed = (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBayerGreenSplitTag(
                TiffConst::TYPE_LONG,
                1,
                pack('V', 0),
                32803,
                [2, 2],
            ),
        );

        self::assertNotNull($parsed->ifd0->get(DngTag::BAYER_GREEN_SPLIT));
    }

    /**
     * BayerGreenSplit rejects wrong type/count layouts.
     */
    #[Test]
    public function rejectsBayerGreenSplitWithWrongTypeOrCount(): void
    {
        $cases = [
            [
                'type'    => TiffConst::TYPE_SHORT,
                'count'   => 1,
                'payload' => pack('v', 1),
            ],
            [
                'type'    => TiffConst::TYPE_LONG,
                'count'   => 2,
                'payload' => pack('V2', 1, 2),
            ],
        ];
        $rejections = 0;

        foreach ($cases as $case) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithBayerGreenSplitTag(
                        $case['type'],
                        $case['count'],
                        $case['payload'],
                        32803,
                        [2, 2],
                    ),
                );
                self::fail('Expected ParseError for invalid BayerGreenSplit type/count.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * BayerGreenSplit rejects negative-domain payloads encoded via signed value types.
     */
    #[Test]
    public function rejectsBayerGreenSplitNegativeDomainPayload(): void
    {
        $this->expectException(ParseError::class);

        (new TiffExifParser())->parseFromBlob(
            $this->buildDngWithBayerGreenSplitTag(
                TiffConst::TYPE_SLONG,
                1,
                pack('V', 0xFFFFFFFF),
                32803,
                [2, 2],
            ),
        );
    }

    /**
     * BayerGreenSplit rejects non-Bayer applicability contexts.
     */
    #[Test]
    public function rejectsBayerGreenSplitInNonBayerContext(): void
    {
        $cases = [
            [2, [2, 2]],    // RGB photometric, not CFA
            [32803, [4, 4]], // CFA but not Bayer 2x2 pattern
        ];
        $rejections = 0;

        foreach ($cases as [$photometric, $repeatDim]) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithBayerGreenSplitTag(
                        TiffConst::TYPE_LONG,
                        1,
                        pack('V', 10),
                        $photometric,
                        $repeatDim,
                    ),
                );
                self::fail('Expected ParseError for non-Bayer BayerGreenSplit applicability.');
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * ChromaBlurRadius, AntiAliasStrength and ShadowScale accept valid RATIONAL[1] values.
     */
    #[Test]
    public function parsesValidChromaBlurRadiusAntiAliasStrengthAndShadowScale(): void
    {
        $cases = [
            [DngTag::CHROMA_BLUR_RADIUS, pack('V2', 0, 1)],
            [DngTag::ANTI_ALIAS_STRENGTH, pack('V2', 0, 1)],
            [DngTag::SHADOW_SCALE, pack('V2', 5, 4)],
        ];

        foreach ($cases as [$tag, $payload]) {
            $parsed = (new TiffExifParser())->parseFromBlob(
                $this->buildDngWithSingleCustomTag(
                    $tag,
                    TiffConst::TYPE_RATIONAL,
                    1,
                    $payload,
                ),
            );

            self::assertNotNull($parsed->ifd0->get($tag));
        }
    }

    /**
     * ChromaBlurRadius, AntiAliasStrength and ShadowScale reject wrong type/count layouts.
     */
    #[Test]
    public function rejectsChromaBlurRadiusAntiAliasStrengthAndShadowScaleWithWrongTypeOrCount(): void
    {
        $cases = [
            [DngTag::CHROMA_BLUR_RADIUS, TiffConst::TYPE_LONG, 1, pack('V', 1)],
            [DngTag::CHROMA_BLUR_RADIUS, TiffConst::TYPE_RATIONAL, 2, pack('V4', 1, 1, 1, 1)],
            [DngTag::ANTI_ALIAS_STRENGTH, TiffConst::TYPE_LONG, 1, pack('V', 1)],
            [DngTag::ANTI_ALIAS_STRENGTH, TiffConst::TYPE_RATIONAL, 2, pack('V4', 1, 1, 1, 1)],
            [DngTag::SHADOW_SCALE, TiffConst::TYPE_LONG, 1, pack('V', 1)],
            [DngTag::SHADOW_SCALE, TiffConst::TYPE_RATIONAL, 2, pack('V4', 1, 1, 1, 1)],
        ];
        $rejections = 0;

        foreach ($cases as [$tag, $type, $count, $payload]) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag($tag, $type, $count, $payload),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid scalar tag layout 0x%04X.', $tag),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * ChromaBlurRadius, AntiAliasStrength and ShadowScale reject invalid scalar values.
     */
    #[Test]
    public function rejectsChromaBlurRadiusAntiAliasStrengthAndShadowScaleWithInvalidValues(): void
    {
        $cases = [
            [DngTag::CHROMA_BLUR_RADIUS, pack('V2', 1, 0)],
            [DngTag::ANTI_ALIAS_STRENGTH, pack('V2', 1, 0)],
            [DngTag::SHADOW_SCALE, pack('V2', 0, 1)],
            [DngTag::SHADOW_SCALE, pack('V2', 1, 0)],
        ];
        $rejections = 0;

        foreach ($cases as [$tag, $payload]) {
            try {
                (new TiffExifParser())->parseFromBlob(
                    $this->buildDngWithSingleCustomTag(
                        $tag,
                        TiffConst::TYPE_RATIONAL,
                        1,
                        $payload,
                    ),
                );
                self::fail(
                    sprintf('Expected ParseError for invalid scalar tag value 0x%04X.', $tag),
                );
            } catch (ParseError) {
                ++$rejections;
            }
        }

        self::assertSame(count($cases), $rejections);
    }

    /**
     * DNG opcode-list tags.
     *
     * @var list<int>
     */
    private const array DNG_OPCODE_LIST_TAGS = [
        DngTag::OPCODE_LIST_1,
        DngTag::OPCODE_LIST_2,
        DngTag::OPCODE_LIST_3,
    ];

    /**
     * Builds a minimal DNG with one OpcodeList tag in IFD0.
     *
     * @param int    $tag     One of OpcodeList1/2/3.
     * @param string $payload Raw opcode-list payload bytes.
     * @param int    $type    TIFF field type for the opcode-list tag.
     */
    private function buildDngWithOpcodeListTag(int $tag, string $payload, int $type = TiffConst::TYPE_UNDEFINED): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadLen        = strlen($payload);
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);
        $inline            = $payloadLen <= 4;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', $type)
            . pack('V', $payloadLen)
            . ($inline
                ? str_pad($payload, 4, "\0")
                : pack('V', $payloadOffset))
            . pack('V', 0)
            . $uniqueCameraModel
            . ($inline ? '' : $payload);
    }

    /**
     * Builds a minimal DNG with one original proxy-size tag in IFD0.
     *
     * @param int    $tag     One of OriginalDefaultFinalSize, OriginalBestQualityFinalSize, OriginalDefaultCropSize.
     * @param int    $type    TIFF field type.
     * @param int    $count   Declared TIFF count value.
     * @param string $payload Raw payload bytes for the tag.
     */
    private function buildDngWithOriginalProxySizeTag(int $tag, int $type, int $count, string $payload): string
    {
        return $this->buildDngWithSingleCustomTag($tag, $type, $count, $payload);
    }

    /**
     * Builds a minimal DNG with one custom tag in IFD0.
     *
     * @param int    $tag     TIFF tag id.
     * @param int    $type    TIFF field type.
     * @param int    $count   Declared TIFF count value.
     * @param string $payload Raw payload bytes for the tag.
     */
    private function buildDngWithSingleCustomTag(int $tag, int $type, int $count, string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadLen        = strlen($payload);
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);
        $inline            = $payloadLen <= 4;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', $type)
            . pack('V', $count)
            . ($inline
                ? str_pad($payload, 4, "\0")
                : pack('V', $payloadOffset))
            . pack('V', 0)
            . $uniqueCameraModel
            . ($inline ? '' : $payload);
    }

    /**
     * Builds a DNG with BayerGreenSplit and optional CFA/Bayer context tags.
     *
     * @param int             $type        TIFF field type for BayerGreenSplit.
     * @param int             $count       Declared TIFF count for BayerGreenSplit.
     * @param string          $payload     Raw BayerGreenSplit payload.
     * @param int|null        $photometric Optional PhotometricInterpretation value.
     * @param array<int>|null $repeatDim   Optional CFARepeatPatternDim [rows, cols].
     */
    private function buildDngWithBayerGreenSplitTag(
        int $type,
        int $count,
        string $payload,
        ?int $photometric,
        ?array $repeatDim,
    ): string {
        $ifdOffset         = 8;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $payloadLength     = strlen($payload);
        $payloadInline     = $payloadLength <= 4;

        $tags = [
            ExifTag::IMAGE_WIDTH => pack('v', ExifTag::IMAGE_WIDTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::IMAGE_LENGTH => pack('v', ExifTag::IMAGE_LENGTH)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 100) . pack('v', 0),
            ExifTag::ORIENTATION => pack('v', ExifTag::ORIENTATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', 1) . pack('v', 0),
            DngTag::DNG_VERSION => pack('v', DngTag::DNG_VERSION)
                . pack('v', TiffConst::TYPE_BYTE)
                . pack('V', 4)
                . pack('C4', 1, 7, 1, 0),
            DngTag::UNIQUE_CAMERA_MODEL => pack('v', DngTag::UNIQUE_CAMERA_MODEL)
                . pack('v', TiffConst::TYPE_ASCII)
                . pack('V', strlen($uniqueCameraModel)),
            DngTag::BAYER_GREEN_SPLIT => pack('v', DngTag::BAYER_GREEN_SPLIT)
                . pack('v', $type)
                . pack('V', $count),
        ];

        if ($photometric !== null) {
            $tags[ExifTag::PHOTOMETRIC_INTERPRETATION] = pack('v', ExifTag::PHOTOMETRIC_INTERPRETATION)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 1)
                . pack('v', $photometric) . pack('v', 0);
        }

        if ($repeatDim !== null) {
            $tags[DngTag::CFA_REPEAT_PATTERN_DIM] = pack('v', DngTag::CFA_REPEAT_PATTERN_DIM)
                . pack('v', TiffConst::TYPE_SHORT)
                . pack('V', 2)
                . pack('v', $repeatDim[0]) . pack('v', $repeatDim[1]);
        }

        ksort($tags);

        $entryCount    = count($tags);
        $ifdSize       = 2 + (12 * $entryCount) + 4;
        $modelOffset   = $ifdOffset + $ifdSize;
        $payloadOffset = $modelOffset + strlen($uniqueCameraModel);

        $ifdEntries = '';
        foreach ($tags as $tag => $entryPrefix) {
            if ($tag === DngTag::UNIQUE_CAMERA_MODEL) {
                $ifdEntries .= $entryPrefix . pack('V', $modelOffset);
                continue;
            }

            if ($tag === DngTag::BAYER_GREEN_SPLIT) {
                $ifdEntries .= $entryPrefix
                    . ($payloadInline
                        ? str_pad($payload, 4, "\0")
                        : pack('V', $payloadOffset));
                continue;
            }

            $ifdEntries .= $entryPrefix;
        }

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . $ifdEntries
            . pack('V', 0)
            . $uniqueCameraModel
            . ($payloadInline ? '' : $payload);
    }

    /**
     * Builds a DNG with ExtraCameraProfiles pointing to an embedded camera profile payload.
     *
     * @param string   $profilePayload        Embedded profile payload bytes.
     * @param int|null $profileOffsetOverride Optional override for the profile offset value.
     */
    private function buildDngWithExtraCameraProfiles(string $profilePayload, ?int $profileOffsetOverride = null): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $profileOffset     = $profileOffsetOverride ?? ($modelOffset + strlen($uniqueCameraModel));

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', DngTag::EXTRA_CAMERA_PROFILES)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $profileOffset)
            . pack('V', 0)
            . $uniqueCameraModel
            . $profilePayload;
    }

    /**
     * Builds a DNG with a SHORT[1] tag inline in IFD0.
     *
     * @param int $tag   Tag constant (must be > 0xC614)
     * @param int $value Tag value
     */
    private function buildDngWithShort1Tag(int $tag, int $value): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', $value) . pack('v', 0)
            . pack('V', 0)
            . $uniqueCameraModel;
    }

    /**
     * Builds a DNG with a preview string tag in IFD0.
     *
     * @param int    $tag     Preview tag constant
     * @param int    $type    TIFF type (ASCII or BYTE)
     * @param string $payload Raw string bytes including NUL terminator
     */
    private function buildDngWithPreviewStringTag(int $tag, int $type, string $payload): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;
        $payloadLen        = strlen($payload);
        $payloadOffset     = $modelOffset + strlen($uniqueCameraModel);
        $inline            = $payloadLen <= 4;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', $type)
            . pack('V', $payloadLen)
            . ($inline
                ? str_pad($payload, 4, "\0")
                : pack('V', $payloadOffset))
            . pack('V', 0)
            . $uniqueCameraModel
            . ($inline ? '' : $payload);
    }

    private function buildDngWithLong1Tag(int $tag, int $value): string
    {
        $ifdOffset         = 8;
        $entryCount        = 6;
        $ifdSize           = 2 + (12 * $entryCount) + 4;
        $uniqueCameraModel = pack('Z*', 'TestCamera0');
        $modelOffset       = $ifdOffset + $ifdSize;

        return 'II'
            . pack('v', TiffConst::MAGIC_CLASSIC)
            . pack('V', $ifdOffset)
            . pack('v', $entryCount)
            . pack('v', ExifTag::IMAGE_WIDTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::IMAGE_LENGTH)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 100) . pack('v', 0)
            . pack('v', ExifTag::ORIENTATION)
            . pack('v', TiffConst::TYPE_SHORT)
            . pack('V', 1)
            . pack('v', 1) . pack('v', 0)
            . pack('v', DngTag::DNG_VERSION)
            . pack('v', TiffConst::TYPE_BYTE)
            . pack('V', 4)
            . pack('C4', 1, 7, 1, 0)
            . pack('v', DngTag::UNIQUE_CAMERA_MODEL)
            . pack('v', TiffConst::TYPE_ASCII)
            . pack('V', strlen($uniqueCameraModel))
            . pack('V', $modelOffset)
            . pack('v', $tag)
            . pack('v', TiffConst::TYPE_LONG)
            . pack('V', 1)
            . pack('V', $value)
            . pack('V', 0)
            . $uniqueCameraModel;
    }
}
