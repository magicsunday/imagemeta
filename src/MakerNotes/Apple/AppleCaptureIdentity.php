<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\MakerNotes\Apple;

/**
 * Unique identifiers for a captured image extracted from Apple maker notes.
 */
final readonly class AppleCaptureIdentity
{
    /**
     * @param string|null     $contentIdentifier     Unique content identifier assigned by Apple platforms.
     * @param string|int|null $imageCaptureRequestId Identifier for the originating image capture request.
     * @param string|null     $burstUuid             Identifier referencing the originating burst.
     * @param string|null     $imageUniqueId         Unique image identifier distinct from EXIF/ImageUniqueID.
     * @param string|null     $photoIdentifier       Photos framework identifier for the asset.
     */
    public function __construct(
        public ?string $contentIdentifier,
        public string|int|null $imageCaptureRequestId,
        public ?string $burstUuid,
        public ?string $imageUniqueId,
        public ?string $photoIdentifier,
    ) {
    }

    /**
     * Creates an instance when at least one field is non-null, or returns null.
     */
    public static function createIfPresent(
        ?string $contentIdentifier,
        string|int|null $imageCaptureRequestId,
        ?string $burstUuid,
        ?string $imageUniqueId,
        ?string $photoIdentifier,
    ): ?self {
        if (
            $contentIdentifier === null
            && $imageCaptureRequestId === null
            && $burstUuid === null
            && $imageUniqueId === null
            && $photoIdentifier === null
        ) {
            return null;
        }

        return new self($contentIdentifier, $imageCaptureRequestId, $burstUuid, $imageUniqueId, $photoIdentifier);
    }
}
