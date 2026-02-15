<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Parse\Icc;

/**
 * Defines the contract for decoding embedded ICC profile payloads.
 */
interface IccParserInterface
{
    /**
     * Decodes ICC profile data from the full profile payload or segmented chunks.
     *
     * @param string|null        $profileData Raw ICC profile payload.
     * @param array<int, string> $segments    ICC profile chunks discovered in container metadata.
     *
     * @return array{
     *     description: string|null,
     *     copyright: string|null,
     *     whitePoint: array{x: float, y: float, z: float}|null,
     *     version: string|null,
     *     pcs: string|null,
     *     renderingIntent: string|null,
     *     profileId: string|null,
     *     cmmType: string|null,
     *     profileClass: string|null,
     *     colorSpace: string|null,
     *     profileDateTime: string|null,
     *     profileDateTimeUtc: string|null,
     *     profileSignature: string|null,
     *     profileFlags: string|null,
     *     primaryPlatform: string|null,
     *     deviceManufacturer: string|null,
     *     deviceModel: string|null,
     *     deviceAttributes: string|null,
     *     profileCreator: string|null,
     *     illuminant: array{x: float, y: float, z: float}|null,
     * }|null
     */
    public function decode(?string $profileData, array $segments = []): ?array;
}
