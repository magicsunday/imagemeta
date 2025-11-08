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
 *
 * Sub-factories may accept additional parameters beyond Metadata to handle
 * dependencies between value objects (e.g., face count for scene detection).
 */
interface SubFactoryInterface
{
    /**
     * Creates a value object from the supplied metadata.
     *
     * @param Metadata $metadata Metadata container with decoded EXIF, XMP and QuickTime data.
     * @param mixed    ...$args  Additional factory-specific parameters.
     *
     * @return mixed The created value object.
     */
    public function create(Metadata $metadata, mixed ...$args): mixed;
}
