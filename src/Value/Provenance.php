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
 * Groups provenance and attribution metadata: author, rights, IPTC, keywords, file, container, standards, and related assets.
 */
final readonly class Provenance
{
    /**
     * @param Author        $author    Author and creator information.
     * @param Rights        $rights    Rights and licensing information.
     * @param Iptc          $iptc      IPTC metadata.
     * @param Keywords      $keywords  Keywords and tags.
     * @param File          $file      File-level metadata.
     * @param Container     $container Container format metadata.
     * @param Standards     $standards Standards compliance metadata.
     * @param RelatedAssets $related   Related assets metadata.
     */
    public function __construct(
        public Author $author,
        public Rights $rights,
        public Iptc $iptc,
        public Keywords $keywords,
        public File $file,
        public Container $container,
        public Standards $standards,
        public RelatedAssets $related,
    ) {
    }
}
