<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Tiff;

use MagicSunday\ImageMeta\Core\BinaryReadAccessInterface;
use MagicSunday\ImageMeta\Core\Endian;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Model\Dng\DngTag;

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
        BinaryReadAccessInterface $buffer,
    ) {
        $support           = new DngValidationSupport($bo, $buffer);
        $this->version     = new DngVersionValidator($support);
        $this->calibration = new DngCalibrationValidator($support);
        $this->profile     = new DngProfileValidator($support);
        $this->geometry    = new DngGeometryValidator($support);
        $this->structure   = new DngStructureValidator($support);
    }

    /**
     * Pre-loop validation: DNG version and required fields.
     *
     * Must be called before the additional-IFD loop.
     */
    public function validatePreLoop(Ifd $ifd0): void
    {
        $this->version->validateDngRequiredVersion();
        $this->structure->validateDngRequiredUniqueCameraModel($ifd0);
    }

    /**
     * Per-IFD validation for additional IFDs (called inside the IFD loop).
     *
     * Covers structural, geometry, and profile constraints that apply to every IFD.
     */
    public function validatePerIfd(Ifd $ifd): void
    {
        $this->structure->validateDngRolePhotometric($ifd);
        $this->structure->validateDngIfd0OnlyTags($ifd);
        $this->structure->validateDngJxlTags($ifd);
        $this->structure->validateDngCfaPhotometric($ifd);

        $this->geometry->validateDngLinearizationTable($ifd);
        $this->geometry->validateDngBayerGreenSplit($ifd);

        $this->profile->validateDngProfileGainTableMapLegacy($ifd);
        $this->structure->validateDngSemanticMaskIdentity($ifd);
        $this->structure->validateDngMaskSubArea($ifd);
    }

    /**
     * IFD0-specific DNG validation covering all sub-validator domains.
     *
     * Runs calibration, version, profile, geometry, and structure constraints
     * that only apply to the primary IFD.
     *
     * @param list<Ifd> $additionalIfds Additional IFDs for cross-IFD validation.
     */
    public function validateIfd0(Ifd $ifd0, array $additionalIfds): void
    {
        // Order preserved from original TiffExifParser call sequence.
        $this->calibration->validateDngMatrixTags($ifd0);
        $this->calibration->validateDngCalibrationIlluminantDomain($ifd0);
        $this->calibration->validateDngIlluminantDependencies($ifd0);

        $this->version->validateDngThirdIlluminantVersionFloor($ifd0);

        $this->calibration->validateDngTripleIlluminant($ifd0);
        $this->calibration->validateDngWhiteBalanceExclusivity($ifd0);
        $this->calibration->validateDngWhiteBalanceLayout($ifd0);
        $this->calibration->validateDngAnalogBalance($ifd0);
        $this->calibration->validateDngIccProfilePairs($ifd0);
        $this->calibration->validateDngCalibrationIlluminantPairZero($ifd0);

        $this->profile->validateDngProfileToneCurve($ifd0);

        $this->version->validateDngInterleaveVersionFloors($ifd0);
        $this->version->validateDngVersionValidity($ifd0);
        $this->version->validateDngBackwardVersionGate($ifd0);
        $this->version->validateDngBackwardVersionConsistency($ifd0);

        $this->calibration->validateDngColorimetricReference($ifd0);

        $this->profile->validateDngMultiProfileName($ifd0, $additionalIfds);
        $this->profile->validateDngExtraCameraProfiles($ifd0);
        $this->profile->validateDngNoiseProfile($ifd0);
        $this->profile->validateDngHueSatMapDims($ifd0);
        $this->profile->validateDngHueSatMapData($ifd0);
        $this->profile->validateDngProfileLookTableDims($ifd0);
        $this->profile->validateDngProfileLookTableData($ifd0);
        $this->profile->validateDngEncodingTag(
            $ifd0,
            DngTag::PROFILE_HUE_SAT_MAP_ENCODING,
            DngTag::PROFILE_HUE_SAT_MAP_DIMS,
            'ProfileHueSatMapEncoding',
        );
        $this->profile->validateDngEncodingTag(
            $ifd0,
            DngTag::PROFILE_LOOK_TABLE_ENCODING,
            DngTag::PROFILE_LOOK_TABLE_DIMS,
            'ProfileLookTableEncoding',
        );

        $this->structure->validateDngDigestTags($ifd0);
        $this->structure->validateDngPreviewDateTime($ifd0);
        $this->structure->validateDngPreviewColorSpace($ifd0);

        $this->geometry->validateDngDefaultBlackRender($ifd0);

        $this->profile->validateDngIlluminantData($ifd0);
        $this->profile->validateDngProfileDynamicRange($ifd0);
        $this->profile->validateDngProfileGainTableMap2($ifd0);
        $this->profile->validateDngGainMapPlacement($ifd0);
        $this->profile->validateDngProfileGainTableMapLegacy($ifd0);

        $this->structure->validateDngImageStats($ifd0);
        $this->structure->validateDngImageSequenceInfo($ifd0);
        $this->structure->validateDngRgbTables($ifd0);
        $this->structure->validateDngOpcodeLists($ifd0);
        $this->structure->validateDngOriginalRawFileData($ifd0);

        $this->geometry->validateDngActiveAndMaskedAreas($ifd0);
        $this->geometry->validateDngBlackWhiteLevelFamily($ifd0);
        $this->geometry->validateDngDefaultCropScaleGeometry($ifd0);
        $this->geometry->validateDngLinearResponseLimit($ifd0);
        $this->geometry->validateDngLinearizationTable($ifd0);
        $this->geometry->validateDngBayerGreenSplit($ifd0);
        $this->geometry->validateDngRenderScalars($ifd0);

        $this->profile->validateDngBaselineExposure($ifd0);

        $this->geometry->validateDngBaselineScalars($ifd0);
        $this->geometry->validateDngLensInfo($ifd0);
        $this->geometry->validateDngBestQualityScale($ifd0);
        $this->geometry->validateDngOriginalProxySizes($ifd0);
        $this->geometry->validateDngDefaultUserCrop($ifd0);

        $this->structure->validateDngDepthEnums($ifd0);
        $this->structure->validateDngNoiseReductionApplied($ifd0);
        $this->structure->validateDngCfaLayoutDomain($ifd0);

        $this->profile->validateDngProfileEmbedPolicy($ifd0);

        $this->structure->validateDngEnhanceParams($ifd0);

        $this->geometry->validateDngSubTileBlockSize($ifd0);
        $this->geometry->validateDngRowInterleaveFactor($ifd0);

        $this->structure->validateDngRequiredOrientation($ifd0);
    }
}
