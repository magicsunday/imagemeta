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
     * @param string|null $copyright              Copyright notice text.
     * @param string|null $usageTerms             Usage terms or rights expression.
     * @param string|null $licenseUrl             URL to the applicable licence.
     * @param string|null $creditLine             Credit line or byline.
     * @param string|null $securityClassification Security classification assigned to the asset.
     */
    public function __construct(
        public readonly ?string $copyright,
        public readonly ?string $usageTerms,
        public readonly ?string $licenseUrl,
        public readonly ?string $creditLine,
        public readonly ?string $securityClassification,
    ) {
    }
}
