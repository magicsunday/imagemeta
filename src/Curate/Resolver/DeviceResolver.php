<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Device;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Resolves device specific metadata from container level sources.
 */
final readonly class DeviceResolver
{
    use XmpPropertyAccess;

    private const string NS_TIFF = 'http://ns.adobe.com/tiff/1.0/';

    private const string NS_XMP = 'http://ns.adobe.com/xap/1.0/';

    private const string QUICKTIME_SOFTWARE_KEY = 'com.apple.quicktime.software';

    /**
     * Builds a device value object from available metadata.
     */
    public function resolve(?QuickTimeMeta $quickTimeMeta, ?XmpDocument $xmpDocument): ?Device
    {
        $software     = null;
        $hostComputer = null;

        if ($quickTimeMeta instanceof QuickTimeMeta) {
            $software = $this->stringFromMixed($quickTimeMeta->keys[self::QUICKTIME_SOFTWARE_KEY] ?? null);
        }

        if ($software === null) {
            $software = $this->xmpString($xmpDocument, self::NS_XMP, 'CreatorTool');
        }

        $hostComputer = $this->xmpString($xmpDocument, self::NS_TIFF, 'HostComputer');

        if ($software === null) {
            // Preserve host computer details from XMP as a best-effort fallback for the software chain.
            $software = $hostComputer;
        }

        if ($software === null) {
            return null;
        }

        return new Device(
            software: $software,
            rawDevelopingSoftware: null,
            imageEditingSoftware: null,
            metadataEditingSoftware: null,
        );
    }

    /**
     * Normalises arbitrary QuickTime metadata values to strings.
     */
    private function stringFromMixed(string|int|float|bool|null $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }
}
