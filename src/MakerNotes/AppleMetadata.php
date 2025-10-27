<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes;

/**
 * Shared Apple metadata enumerations used across maker note and QuickTime resolvers.
 */
final class AppleMetadata
{
    /**
     * Maps maker note keys to normalised flag identifiers.
     *
     * @var array<string, string>
     */
    public const array FLAG_MAP = [
        'AEStable'             => 'aeStable',
        'AFStable'             => 'afStable',
        'LivePhotoAuto'        => 'livePhotoAuto',
        'LivePhotoEnabled'     => 'livePhotoEnabled',
        'LivePhotoActive'      => 'livePhotoActive',
        'LivePhotoLongExposure' => 'livePhotoLongExposure',
        'LivePhoto'            => 'livePhoto',
        'PersonInPhoto'        => 'personInPhoto',
        'PetInPhoto'           => 'petInPhoto',
        'HdrAuto'              => 'hdrAuto',
        'HdrEnabled'           => 'hdrEnabled',
        'NightMode'            => 'nightMode',
        'LongExposure'         => 'longExposure',
    ];

    /**
     * Maps HDR image type codes to descriptive labels.
     *
     * @var array<int, string>
     */
    public const array HDR_IMAGE_TYPES = [
        0 => 'Standard',
        1 => 'HDR',
        2 => 'HDR2',
        3 => 'HDR Image',
        4 => 'Original Image',
    ];

    /**
     * Maps image capture type codes to descriptive labels.
     *
     * @var array<int, string>
     */
    public const array IMAGE_CAPTURE_TYPES = [
        0  => 'Unknown',
        1  => 'ProRAW',
        2  => 'Portrait',
        3  => 'Live Photo',
        4  => 'Live Photo Long Exposure',
        5  => 'Burst',
        6  => 'Night Mode',
        7  => 'Night Mode Portrait',
        10 => 'Photo',
        11 => 'Manual Focus',
        12 => 'Scene',
    ];
}
