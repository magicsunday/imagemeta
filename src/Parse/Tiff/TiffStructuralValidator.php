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

    /**
     * Validates EnhancedIfd coupling for a single IFD.
     *
     * Called separately because IFD0 runs this before the additional-IFD loop.
     */
    public function validateEnhancedIfd(Ifd $ifd): void
    {
        $this->tag->validateEnhancedIfd($ifd);
    }

    /**
     * Runs all common per-IFD structural validations.
     *
     * Covers tag constraints, JPEG fields, sample tags, color/ink tags.
     * Called for every IFD (both additional IFDs and IFD0).
     *
     * @param bool $strictTiffNewSubfileType Whether to enforce strict TIFF NewSubfileType rules.
     */
    public function validatePerIfd(Ifd $ifd, bool $strictTiffNewSubfileType): void
    {
        $this->tag->validateSubfileAndPageTags($ifd, $strictTiffNewSubfileType);
        $this->tag->validatePositionTags($ifd);
        $this->tag->validateThreshholdingAndCellTags($ifd);
        $this->tag->validateFreeSpaceTags($ifd);
        $this->tag->validateFillOrderTag($ifd);
        $this->tag->validatePredictorTag($ifd);
        $this->tag->validateFaxOptionTags($ifd);

        $this->jpeg->validateJpegProcTag($ifd);
        $this->jpeg->validateJpegRestartIntervalTag($ifd);
        $this->jpeg->validateJpegLosslessTags($ifd);
        $this->jpeg->validateJpegTableTags($ifd);
        $this->jpeg->validateJpegInterchangePairTags($ifd);

        $this->sample->validateMinMaxSampleValueTags($ifd);
        $this->sample->validateSampleDomainTags($ifd);
        $this->sample->validateExtraSamplesTag($ifd);
        $this->sample->validateGrayResponseTags($ifd);
        $this->sample->validateHalftoneHintsTag($ifd);

        $this->colorInk->validateSeparatedImageInkTags($ifd);
        $this->colorInk->validateSeparatedImageDotRange($ifd);
        $this->colorInk->validateTransferFamilyTags($ifd);
        $this->colorInk->validatePaletteColorMapTag($ifd);
    }

    /**
     * Runs IFD0-specific structural validations plus all common per-IFD rules.
     *
     * @param bool $jpegContext              Whether the TIFF is embedded in a JPEG container.
     * @param bool $strictTiffNewSubfileType Whether to enforce strict TIFF NewSubfileType rules.
     */
    public function validateIfd0(Ifd $ifd0, ?Ifd $ifd1, bool $jpegContext, bool $strictTiffNewSubfileType): void
    {
        $this->tag->validateCompressionDomain($ifd0, $ifd1, $jpegContext);
        $this->validatePerIfd($ifd0, $strictTiffNewSubfileType);
    }

    /**
     * Validates image dimension and data layout tags (strip/tile).
     *
     * Only applicable when dimensions are not provided externally
     * (i.e. not in JPEG or ISO BMFF container context).
     */
    public function validateImageData(Ifd $ifd0): void
    {
        $this->tag->validateImageDimensions($ifd0);
        $this->imageData->validateStripLayoutConsistency($ifd0);
        $this->imageData->validateTileLayoutConsistency($ifd0);
    }
}
