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
        public ?string $copyright,
        public ?string $usageTerms,
        public ?string $licenseUrl,
        public ?string $creditLine,
        public ?string $securityClassification,
    ) {
    }

    /**
     * Returns the copyright notice text.
     */
    public function copyright(): ?string
    {
        return $this->copyright;
    }

    /**
     * Returns the usage terms or rights expression.
     */
    public function usageTerms(): ?string
    {
        return $this->usageTerms;
    }

    /**
     * Returns the licence URL when provided.
     */
    public function licenseUrl(): ?string
    {
        return $this->licenseUrl;
    }

    /**
     * Returns the credit line or byline.
     */
    public function creditLine(): ?string
    {
        return $this->creditLine;
    }

    /**
     * Returns the security classification assigned to the asset.
     */
    public function securityClassification(): ?string
    {
        return $this->securityClassification;
    }
}
