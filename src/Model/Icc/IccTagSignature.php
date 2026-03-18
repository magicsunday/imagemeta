<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Icc;

/**
 * Defines common ICC tag signatures.
 *
 * ICC.1:2022 §9 defines the tag table and tag signature registry.
 */
final class IccTagSignature
{
    /**
     * Profile description tag (desc).
     */
    public const string PROFILE_DESCRIPTION  = 'desc';

    /**
     * Profile copyright tag (cprt).
     */
    public const string PROFILE_COPYRIGHT    = 'cprt';

    /**
     * Media white point tag (wtpt).
     */
    public const string MEDIA_WHITE_POINT    = 'wtpt';

    /**
     * Red matrix column tag (rXYZ).
     */
    public const string RED_MATRIX_COLUMN    = 'rXYZ';

    /**
     * Green matrix column tag (gXYZ).
     */
    public const string GREEN_MATRIX_COLUMN  = 'gXYZ';

    /**
     * Blue matrix column tag (bXYZ).
     */
    public const string BLUE_MATRIX_COLUMN   = 'bXYZ';

    /**
     * Red tone reproduction curve tag (rTRC).
     */
    public const string RED_TRC              = 'rTRC';

    /**
     * Green tone reproduction curve tag (gTRC).
     */
    public const string GREEN_TRC            = 'gTRC';

    /**
     * Blue tone reproduction curve tag (bTRC).
     */
    public const string BLUE_TRC             = 'bTRC';

    /**
     * Gray tone reproduction curve tag (kTRC).
     */
    public const string GRAY_TRC             = 'kTRC';

    /**
     * Luminance tag (lumi).
     */
    public const string LUMINANCE            = 'lumi';

    /**
     * Chromatic adaptation matrix tag (chad).
     */
    public const string CHROMATIC_ADAPTATION = 'chad';

    /**
     * Viewing conditions tag (view).
     */
    public const string VIEWING_CONDITIONS   = 'view';

    /**
     * Measurement tag (meas).
     */
    public const string MEASUREMENT          = 'meas';

    /**
     * Technology tag (tech).
     */
    public const string TECHNOLOGY           = 'tech';

    private function __construct()
    {
    }
}
