<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Exif\Model\Ifd;

/**
 * Validates TIFF 6.0 structural and semantic constraints on IFD entries.
 *
 * TIFF 6.0 defines baseline directory semantics, field type rules, and cross-tag
 * dependencies. This orchestrator delegates all validation logic to focused
 * sub-validators.
 */
final readonly class TiffStructuralValidator
{
    private TiffTagConstraintValidator $tag;

    private TiffJpegValidator $jpeg;

    private TiffSampleValidator $sample;

    private TiffColorInkValidator $colorInk;

    private TiffImageDataValidator $imageData;

    public function __construct(
        MemoryBuffer $buffer,
    ) {
        $support         = new TiffValidationSupport($buffer);
        $this->tag       = new TiffTagConstraintValidator($support);
        $this->jpeg      = new TiffJpegValidator($support);
        $this->sample    = new TiffSampleValidator($support);
        $this->colorInk  = new TiffColorInkValidator($support);
        $this->imageData = new TiffImageDataValidator($support);
    }

    // ── Tag constraints ──────────────────────────────────────────────────

    public function validateEnhancedIfd(Ifd $ifd): void
    {
        $this->tag->validateEnhancedIfd($ifd);
    }

    public function validateResolutionEquality(Ifd $ifd): void
    {
        $this->tag->validateResolutionEquality($ifd);
    }

    public function validateCompressionDomain(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext): void
    {
        $this->tag->validateCompressionDomain($ifd0, $ifd1, $jpegContext);
    }

    public function validateFaxOptionTags(Ifd $ifd): void
    {
        $this->tag->validateFaxOptionTags($ifd);
    }

    public function validateFillOrderTag(Ifd $ifd): void
    {
        $this->tag->validateFillOrderTag($ifd);
    }

    public function validateSubfileAndPageTags(Ifd $ifd, bool $strictTiffNewSubfileType): void
    {
        $this->tag->validateSubfileAndPageTags($ifd, $strictTiffNewSubfileType);
    }

    public function validateThreshholdingAndCellTags(Ifd $ifd): void
    {
        $this->tag->validateThreshholdingAndCellTags($ifd);
    }

    public function validatePositionTags(Ifd $ifd): void
    {
        $this->tag->validatePositionTags($ifd);
    }

    public function validateFreeSpaceTags(Ifd $ifd): void
    {
        $this->tag->validateFreeSpaceTags($ifd);
    }

    public function validatePredictorTag(Ifd $ifd): void
    {
        $this->tag->validatePredictorTag($ifd);
    }

    public function validateImageDimensions(Ifd $ifd0): void
    {
        $this->tag->validateImageDimensions($ifd0);
    }

    // ── JPEG ─────────────────────────────────────────────────────────────

    public function validateJpegProcTag(Ifd $ifd): void
    {
        $this->jpeg->validateJpegProcTag($ifd);
    }

    public function validateJpegLosslessTags(Ifd $ifd): void
    {
        $this->jpeg->validateJpegLosslessTags($ifd);
    }

    public function validateJpegRestartIntervalTag(Ifd $ifd): void
    {
        $this->jpeg->validateJpegRestartIntervalTag($ifd);
    }

    public function validateJpegTableTags(Ifd $ifd): void
    {
        $this->jpeg->validateJpegTableTags($ifd);
    }

    public function validateJpegInterchangePairTags(Ifd $ifd): void
    {
        $this->jpeg->validateJpegInterchangePairTags($ifd);
    }

    // ── Sample ───────────────────────────────────────────────────────────

    public function validateMinMaxSampleValueTags(Ifd $ifd): void
    {
        $this->sample->validateMinMaxSampleValueTags($ifd);
    }

    public function validateSampleDomainTags(Ifd $ifd): void
    {
        $this->sample->validateSampleDomainTags($ifd);
    }

    public function validateExtraSamplesTag(Ifd $ifd): void
    {
        $this->sample->validateExtraSamplesTag($ifd);
    }

    public function validateGrayResponseTags(Ifd $ifd): void
    {
        $this->sample->validateGrayResponseTags($ifd);
    }

    public function validateHalftoneHintsTag(Ifd $ifd): void
    {
        $this->sample->validateHalftoneHintsTag($ifd);
    }

    // ── Color / Ink ──────────────────────────────────────────────────────

    public function validateSeparatedImageInkTags(Ifd $ifd): void
    {
        $this->colorInk->validateSeparatedImageInkTags($ifd);
    }

    public function validateSeparatedImageDotRange(Ifd $ifd): void
    {
        $this->colorInk->validateSeparatedImageDotRange($ifd);
    }

    public function validateTransferFamilyTags(Ifd $ifd): void
    {
        $this->colorInk->validateTransferFamilyTags($ifd);
    }

    public function validatePaletteColorMapTag(Ifd $ifd): void
    {
        $this->colorInk->validatePaletteColorMapTag($ifd);
    }

    // ── Image data layout ────────────────────────────────────────────────

    public function validateStripLayoutConsistency(Ifd $ifd0): void
    {
        $this->imageData->validateStripLayoutConsistency($ifd0);
    }

    public function validateTileLayoutConsistency(Ifd $ifd0): void
    {
        $this->imageData->validateTileLayoutConsistency($ifd0);
    }
}
