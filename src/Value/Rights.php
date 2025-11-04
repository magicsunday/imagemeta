<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Value;

/**
 * Encapsulates copyright and licensing information.
 */
final readonly class Rights
{
    /**
     * Creates a copyright and licensing metadata value object.
     *
     * @param string|null $copyright              Copyright notice text.
     * @param string|null $usageTerms             Usage terms or rights expression.
     * @param string|null $licenseUrl             URL to the applicable licence.
     * @param string|null $creditLine             Credit line or byline.
     * @param string|null $securityClassification Security classification assigned to the asset.
     */
    public function __construct(
        public ?string $copyright,
        public ?string $usageTerms,
        public ?string $licenseUrl,
        public ?string $creditLine,
        public ?string $securityClassification,
    ) {
    }
}
