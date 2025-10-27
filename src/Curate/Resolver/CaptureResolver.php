<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Curate\Resolver;

use DateTimeImmutable;
use MagicSunday\ImageMeta\Curate\Xmp\XmpReader;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Capture;

use function strtotime;
use function trim;

/**
 * Resolves capture timestamps while preferring EXIF derived values.
 */
final readonly class CaptureResolver
{

    private const string NS_EXIF = 'http://ns.adobe.com/exif/1.0/';

    /**
     * Builds a capture value object from the available metadata sources.
     */
    public function resolve(?ExifDocument $exifDocument, ?XmpDocument $xmpDocument): ?Capture
    {
        $dateTime = $exifDocument?->captureDateTime();

        if (!$dateTime instanceof DateTimeImmutable) {
            $dateString = XmpReader::string($xmpDocument, self::NS_EXIF, 'DateTimeOriginal');
            $dateTime   = $dateString !== null ? $this->parseDateTime($dateString) : null;
        }

        if (!$dateTime instanceof DateTimeImmutable) {
            return null;
        }

        return new Capture(
            dateTime: $dateTime,
            temperatureC: null,
            humidityPercent: null,
            pressureHPa: null,
            batteryLevelPercent: $exifDocument?->batteryLevelPercent(),
            waterDepthM: null,
            accelerationMs2: null,
            cameraElevationAngleDeg: null,
            selfTimerModeSeconds: $exifDocument?->selfTimerModeSeconds(),
        );
    }

    /**
     * Parses either EXIF or ISO-8601 style timestamps.
     */
    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y:m:d H:i:s',
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value);
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return new DateTimeImmutable('@' . $timestamp);
        }

        return null;
    }
}
