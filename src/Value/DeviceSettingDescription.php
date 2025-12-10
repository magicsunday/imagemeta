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
 * Represents structured device setting description information.
 *
 * EXIF 3.0 §4.6.6.7.45: This tag indicates information on the picture-taking conditions
 * of a particular camera model. The tag is used only to indicate the picture-taking
 * conditions in the Exif/DCF reader.
 *
 * The information is recorded in the format:
 * - 2 bytes SHORT: Display columns
 * - 2 bytes SHORT: Display rows
 * - Remaining bytes: Camera settings (Unicode UTF-16, NULL-terminated, multiple strings allowed)
 */
final readonly class DeviceSettingDescription
{
    /**
     * Creates a device setting description value object.
     *
     * @param int          $columns  Number of display columns for the settings grid.
     * @param int          $rows     Number of display rows for the settings grid.
     * @param list<string> $settings Camera settings strings decoded to UTF-8.
     */
    public function __construct(
        public int $columns,
        public int $rows,
        public array $settings,
    ) {
    }
}
