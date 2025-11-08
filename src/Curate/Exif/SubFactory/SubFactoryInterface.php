<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Exif\SubFactory;

use MagicSunday\ImageMeta\Model\Metadata;

/**
 * Contract for specialized sub-factories that create specific value objects.
 */
interface SubFactoryInterface
{
    /**
     * Creates a value object from the supplied metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     *
     * @return mixed The created value object.
     */
    public function create(Metadata $metadata): mixed;
}
