<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Core\MemoryBuffer;
use MagicSunday\ImageMeta\Exif\Model\Ifd;

/**
 * Validates DNG (Digital Negative) structural and semantic constraints.
 *
 * DNG 1.7.1.0 defines type/count rules, version gating, cross-tag dependencies,
 * and binary payload layouts. This orchestrator delegates all validation logic
 * to focused sub-validators.
 */
final readonly class DngValidator
{
    private DngVersionValidator $version;

    private DngCalibrationValidator $calibration;

    private DngProfileValidator $profile;

    private DngGeometryValidator $geometry;

    private DngStructureValidator $structure;

    public function __construct(
        Endian $bo,
        MemoryBuffer $buffer,
    ) {
        $support           = new DngValidationSupport($bo, $buffer);
        $this->version     = new DngVersionValidator($support);
        $this->calibration = new DngCalibrationValidator($support);
        $this->profile     = new DngProfileValidator($support);
        $this->geometry    = new DngGeometryValidator($support);
        $this->structure   = new DngStructureValidator($support);
    }

    // ── Version ──────────────────────────────────────────────────────────

    public function validateDngVersionValidity(Ifd $ifd): void
    {
        $this->version->validateDngVersionValidity($ifd);
    }

    public function validateDngBackwardVersionGate(Ifd $ifd): void
    {
        $this->version->validateDngBackwardVersionGate($ifd);
    }

    public function validateDngBackwardVersionConsistency(Ifd $ifd): void
    {
        $this->version->validateDngBackwardVersionConsistency($ifd);
    }

    public function validateDngRequiredVersion(Ifd $ifd): void
    {
        $this->version->validateDngRequiredVersion($ifd);
    }

    public function validateDngInterleaveVersionFloors(Ifd $ifd): void
    {
        $this->version->validateDngInterleaveVersionFloors($ifd);
    }

    public function validateDngThirdIlluminantVersionFloor(Ifd $ifd): void
    {
        $this->version->validateDngThirdIlluminantVersionFloor($ifd);
    }

    // ── Calibration ──────────────────────────────────────────────────────

    public function validateDngMatrixTags(Ifd $ifd): void
    {
        $this->calibration->validateDngMatrixTags($ifd);
    }

    public function validateDngCalibrationIlluminantDomain(Ifd $ifd): void
    {
        $this->calibration->validateDngCalibrationIlluminantDomain($ifd);
    }

    public function validateDngIlluminantDependencies(Ifd $ifd): void
    {
        $this->calibration->validateDngIlluminantDependencies($ifd);
    }

    public function validateDngTripleIlluminant(Ifd $ifd): void
    {
        $this->calibration->validateDngTripleIlluminant($ifd);
    }

    public function validateDngWhiteBalanceExclusivity(Ifd $ifd): void
    {
        $this->calibration->validateDngWhiteBalanceExclusivity($ifd);
    }

    public function validateDngWhiteBalanceLayout(Ifd $ifd): void
    {
        $this->calibration->validateDngWhiteBalanceLayout($ifd);
    }

    public function validateDngAnalogBalance(Ifd $ifd): void
    {
        $this->calibration->validateDngAnalogBalance($ifd);
    }

    public function validateDngCalibrationIlluminantPairZero(Ifd $ifd): void
    {
        $this->calibration->validateDngCalibrationIlluminantPairZero($ifd);
    }

    // ── Profile ──────────────────────────────────────────────────────────

    public function validateDngProfileToneCurve(Ifd $ifd): void
    {
        $this->profile->validateDngProfileToneCurve($ifd);
    }

    public function validateDngHueSatMapDims(Ifd $ifd): void
    {
        $this->profile->validateDngHueSatMapDims($ifd);
    }

    public function validateDngHueSatMapData(Ifd $ifd): void
    {
        $this->profile->validateDngHueSatMapData($ifd);
    }

    public function validateDngIlluminantData(Ifd $ifd): void
    {
        $this->profile->validateDngIlluminantData($ifd);
    }

    public function validateDngProfileLookTableDims(Ifd $ifd): void
    {
        $this->profile->validateDngProfileLookTableDims($ifd);
    }

    public function validateDngProfileLookTableData(Ifd $ifd): void
    {
        $this->profile->validateDngProfileLookTableData($ifd);
    }

    public function validateDngIccProfilePairs(Ifd $ifd): void
    {
        $this->calibration->validateDngIccProfilePairs($ifd);
    }

    public function validateDngBaselineExposure(Ifd $ifd): void
    {
        $this->profile->validateDngBaselineExposure($ifd);
    }

    public function validateDngProfileEmbedPolicy(Ifd $ifd): void
    {
        $this->profile->validateDngProfileEmbedPolicy($ifd);
    }

    public function validateDngNoiseProfile(Ifd $ifd): void
    {
        $this->profile->validateDngNoiseProfile($ifd);
    }

    public function validateDngEncodingTag(Ifd $ifd, int $encTag, int $dimsTag, string $name): void
    {
        $this->profile->validateDngEncodingTag($ifd, $encTag, $dimsTag, $name);
    }

    public function validateDngProfileDynamicRange(Ifd $ifd): void
    {
        $this->profile->validateDngProfileDynamicRange($ifd);
    }

    public function validateDngProfileGainTableMap2(Ifd $ifd): void
    {
        $this->profile->validateDngProfileGainTableMap2($ifd);
    }

    public function validateDngProfileGainTableMapLegacy(Ifd $ifd): void
    {
        $this->profile->validateDngProfileGainTableMapLegacy($ifd);
    }

    public function validateDngGainMapPlacement(Ifd $ifd): void
    {
        $this->profile->validateDngGainMapPlacement($ifd);
    }

    /**
     * @param list<Ifd> $additionalIfds
     */
    public function validateDngMultiProfileName(Ifd $ifd0, array $additionalIfds): void
    {
        $this->profile->validateDngMultiProfileName($ifd0, $additionalIfds);
    }

    public function validateDngExtraCameraProfiles(Ifd $ifd): void
    {
        $this->profile->validateDngExtraCameraProfiles($ifd);
    }

    // ── Geometry ─────────────────────────────────────────────────────────

    public function validateDngActiveAndMaskedAreas(Ifd $ifd): void
    {
        $this->geometry->validateDngActiveAndMaskedAreas($ifd);
    }

    public function validateDngBlackWhiteLevelFamily(Ifd $ifd): void
    {
        $this->geometry->validateDngBlackWhiteLevelFamily($ifd);
    }

    public function validateDngDefaultCropScaleGeometry(Ifd $ifd): void
    {
        $this->geometry->validateDngDefaultCropScaleGeometry($ifd);
    }

    public function validateDngOriginalProxySizes(Ifd $ifd): void
    {
        $this->geometry->validateDngOriginalProxySizes($ifd);
    }

    public function validateDngBestQualityScale(Ifd $ifd): void
    {
        $this->geometry->validateDngBestQualityScale($ifd);
    }

    public function validateDngLinearResponseLimit(Ifd $ifd): void
    {
        $this->geometry->validateDngLinearResponseLimit($ifd);
    }

    public function validateDngLinearizationTable(Ifd $ifd): void
    {
        $this->geometry->validateDngLinearizationTable($ifd);
    }

    public function validateDngDefaultUserCrop(Ifd $ifd): void
    {
        $this->geometry->validateDngDefaultUserCrop($ifd);
    }

    public function validateDngDefaultBlackRender(Ifd $ifd): void
    {
        $this->geometry->validateDngDefaultBlackRender($ifd);
    }

    public function validateDngBaselineScalars(Ifd $ifd): void
    {
        $this->geometry->validateDngBaselineScalars($ifd);
    }

    public function validateDngRenderScalars(Ifd $ifd): void
    {
        $this->geometry->validateDngRenderScalars($ifd);
    }

    public function validateDngLensInfo(Ifd $ifd): void
    {
        $this->geometry->validateDngLensInfo($ifd);
    }

    public function validateDngBayerGreenSplit(Ifd $ifd): void
    {
        $this->geometry->validateDngBayerGreenSplit($ifd);
    }

    // ── Structure ────────────────────────────────────────────────────────

    public function validateDngRequiredOrientation(Ifd $ifd): void
    {
        $this->structure->validateDngRequiredOrientation($ifd);
    }

    public function validateDngRequiredUniqueCameraModel(Ifd $ifd): void
    {
        $this->structure->validateDngRequiredUniqueCameraModel($ifd);
    }

    public function validateDngRolePhotometric(Ifd $ifd): void
    {
        $this->structure->validateDngRolePhotometric($ifd);
    }

    public function validateDngIfd0OnlyTags(Ifd $ifd): void
    {
        $this->structure->validateDngIfd0OnlyTags($ifd);
    }

    public function validateDngJxlTags(Ifd $ifd): void
    {
        $this->structure->validateDngJxlTags($ifd);
    }

    public function validateDngCfaPhotometric(Ifd $ifd): void
    {
        $this->structure->validateDngCfaPhotometric($ifd);
    }

    public function validateDngCfaLayoutDomain(Ifd $ifd): void
    {
        $this->structure->validateDngCfaLayoutDomain($ifd);
    }

    public function validateDngColorimetricReference(Ifd $ifd): void
    {
        $this->calibration->validateDngColorimetricReference($ifd);
    }

    public function validateDngOpcodeLists(Ifd $ifd): void
    {
        $this->structure->validateDngOpcodeLists($ifd);
    }

    public function validateDngOriginalRawFileData(Ifd $ifd): void
    {
        $this->structure->validateDngOriginalRawFileData($ifd);
    }

    public function validateDngRgbTables(Ifd $ifd): void
    {
        $this->structure->validateDngRgbTables($ifd);
    }

    public function validateDngSemanticMaskIdentity(Ifd $ifd): void
    {
        $this->structure->validateDngSemanticMaskIdentity($ifd);
    }

    public function validateDngMaskSubArea(Ifd $ifd): void
    {
        $this->structure->validateDngMaskSubArea($ifd);
    }

    public function validateDngImageStats(Ifd $ifd): void
    {
        $this->structure->validateDngImageStats($ifd);
    }

    public function validateDngImageSequenceInfo(Ifd $ifd): void
    {
        $this->structure->validateDngImageSequenceInfo($ifd);
    }

    public function validateDngDigestTags(Ifd $ifd): void
    {
        $this->structure->validateDngDigestTags($ifd);
    }

    public function validateDngPreviewColorSpace(Ifd $ifd): void
    {
        $this->structure->validateDngPreviewColorSpace($ifd);
    }

    public function validateDngPreviewDateTime(Ifd $ifd): void
    {
        $this->structure->validateDngPreviewDateTime($ifd);
    }

    public function validateDngDepthEnums(Ifd $ifd): void
    {
        $this->structure->validateDngDepthEnums($ifd);
    }

    public function validateDngSubTileBlockSize(Ifd $ifd): void
    {
        $this->geometry->validateDngSubTileBlockSize($ifd);
    }

    public function validateDngRowInterleaveFactor(Ifd $ifd): void
    {
        $this->geometry->validateDngRowInterleaveFactor($ifd);
    }

    public function validateDngNoiseReductionApplied(Ifd $ifd): void
    {
        $this->structure->validateDngNoiseReductionApplied($ifd);
    }

    public function validateDngEnhanceParams(Ifd $ifd): void
    {
        $this->structure->validateDngEnhanceParams($ifd);
    }
}
