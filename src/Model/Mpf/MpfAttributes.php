<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Model\Mpf;

/**
 * Represents optional MP Attribute IFD fields describing the image set.
 *
 * @phpstan-type MpfRational = array{numerator:int, denominator:int}
 * @phpstan-type MpfAttributeValue = int|string|list<int>|MpfRational|list<MpfRational>
 */
final readonly class MpfAttributes
{
    /**
     * @param string|null                                      $imageUidList          Concatenated 128-bit image UIDs when provided.
     * @param int|null                                         $totalFrames           Total number of frames reported for the MP set.
     * @param int|null                                         $individualImageNumber Sequence number of the current image within the set.
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAngle         Optional panorama angle rational values.
     * @param list<array{numerator:int, denominator:int}>|null $panoramaAxis          Optional panorama axis rational values.
     * @param array<int, int|string|array>                     $additionalTags        Remaining MP attribute tags retained in raw form.
     *
     * @phpstan-param array<int, MpfAttributeValue> $additionalTags   Remaining MP attribute tags retained in raw form.
     */
    public function __construct(
        public ?string $imageUidList,
        public ?int $totalFrames,
        public ?int $individualImageNumber,
        /** @var list<array{numerator:int, denominator:int}>|null */
        public ?array $panoramaAngle,
        /** @var list<array{numerator:int, denominator:int}>|null */
        public ?array $panoramaAxis,
        /** @var array<int, MpfAttributeValue> */
        public array $additionalTags,
    ) {
    }
}
