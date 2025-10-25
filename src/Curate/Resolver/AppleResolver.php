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
use MagicSunday\ImageMeta\Value\Apple;

use function is_numeric;
use function preg_split;
use function trim;

/**
 * Resolves Apple specific metadata from QuickTime containers.
 */
final readonly class AppleResolver
{
    /**
     * @var array<int, string>
     */
    private const array HDR_IMAGE_TYPE_MAP = [
        0 => 'Standard',
        1 => 'HDR',
        2 => 'HDR2',
        3 => 'HDR3',
    ];

    /**
     * @var array<int, string>
     */
    private const array IMAGE_CAPTURE_TYPE_MAP = [
        0  => 'Unknown',
        1  => 'ProRAW',
        2  => 'Portrait',
        3  => 'Live Photo',
        4  => 'Live Photo Long Exposure',
        5  => 'Burst',
        6  => 'Night Mode',
        7  => 'Night Mode Portrait',
        10 => 'Photo',
        11 => 'Manual Focus',
        12 => 'Scene',
    ];

    /**
     * @var array<string, string>
     */
    private const array FLAG_KEYS = [
        'LivePhotoAuto'         => 'livePhotoAuto',
        'LivePhotoEnabled'      => 'livePhotoEnabled',
        'LivePhotoActive'       => 'livePhotoActive',
        'LivePhotoLongExposure' => 'livePhotoLongExposure',
        'LivePhoto'             => 'livePhoto',
        'HdrAuto'               => 'hdrAuto',
        'HdrEnabled'            => 'hdrEnabled',
        'NightMode'             => 'nightMode',
        'LongExposure'          => 'longExposure',
        'PersonInPhoto'         => 'personInPhoto',
        'PetInPhoto'            => 'petInPhoto',
    ];

    /**
     * Builds an Apple value object from available metadata.
     */
    public function resolve(?QuickTimeMeta $quickTimeMeta): ?Apple
    {
        if (!$quickTimeMeta instanceof QuickTimeMeta) {
            return null;
        }

        $identifier = $quickTimeMeta->contentIdentifier();
        $resolver   = new QuickTimeResolver($quickTimeMeta);

        $cameraTypeString  = $resolver->string('CameraType');
        $cameraType        = $cameraTypeString ?? $resolver->int('CameraType');
        $hdrHeadroom       = $resolver->float('HdrHeadroom') ?? $resolver->float('HDRHeadroom');
        $hdrGain           = $this->floatList($resolver, 'HdrGain', 'HDRGain');
        $snr               = $resolver->float('SNRSetting') ?? $resolver->float('SNR');
        $focusPosition     = $resolver->float('FocusPosition');
        $livePhotoIndex    = $resolver->int('LivePhotoVideoIndex');
        $colorTemperature  = $resolver->int('ColorTemperature');
        $semanticPreset    = $resolver->string('SemanticStylePreset');
        $semanticWarmth    = $resolver->float('SemanticStyleWarmth');
        $semanticTone      = $resolver->float('SemanticStyleTone');
        $accelerationVector = $this->floatList($resolver, 'AccelerationVector');
        $flags             = $this->flags($resolver);

        $makerNoteVersion  = $resolver->string('MakerNoteVersion');
        $hdrImageType      = $this->enumeratedValue($resolver, self::HDR_IMAGE_TYPE_MAP, 'HDRImageType', 'HdrImageType');
        $burstUuid         = $resolver->string('BurstUUID');
        $focusDistanceRange = $this->focusDistanceRange($resolver);
        $oisMode           = $this->stringOrNumeric($resolver, 'OISMode');
        $imageCaptureType  = $this->enumeratedValue($resolver, self::IMAGE_CAPTURE_TYPE_MAP, 'ImageCaptureType');
        $imageUniqueId     = $resolver->string('ImageUniqueID');
        $photoIdentifier   = $resolver->string('PhotoIdentifier');
        $afMeasuredDepth   = $resolver->float('AFMeasuredDepth');
        $afConfidence      = $resolver->float('AFConfidence');

        if (
            $identifier === null
            && $cameraType === null
            && $hdrHeadroom === null
            && $hdrGain === null
            && $snr === null
            && $focusPosition === null
            && $livePhotoIndex === null
            && $colorTemperature === null
            && $semanticPreset === null
            && $semanticWarmth === null
            && $semanticTone === null
            && $accelerationVector === null
            && $flags === []
            && $makerNoteVersion === null
            && $hdrImageType === null
            && $burstUuid === null
            && $focusDistanceRange === null
            && $oisMode === null
            && $imageCaptureType === null
            && $imageUniqueId === null
            && $photoIdentifier === null
            && $afMeasuredDepth === null
            && $afConfidence === null
        ) {
            return null;
        }

        return new Apple(
            $identifier,
            $cameraType,
            $hdrHeadroom,
            $hdrGain,
            $snr,
            $focusPosition,
            $livePhotoIndex,
            null,
            $colorTemperature,
            $semanticPreset,
            $semanticWarmth,
            $semanticTone,
            $flags,
            $accelerationVector,
            null,
            $makerNoteVersion,
            $hdrImageType,
            $burstUuid,
            $focusDistanceRange,
            $oisMode,
            $imageCaptureType,
            $imageUniqueId,
            $photoIdentifier,
            $afMeasuredDepth,
            $afConfidence,
        );
    }

    /**
     * @return list<float>|null
     */
    private function floatList(QuickTimeResolver $resolver, string ...$keys): ?array
    {
        foreach ($keys as $key) {
            $raw = $resolver->string($key);
            if ($raw === null) {
                continue;
            }

            $parts = preg_split('/[\\s,]+/', $raw);
            if ($parts === false) {
                continue;
            }

            $values = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                if (is_numeric($part)) {
                    $values[] = (float) $part;
                }
            }

            if ($values !== []) {
                return $values;
            }
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    private function focusDistanceRange(QuickTimeResolver $resolver): ?array
    {
        $range = $this->floatList($resolver, 'FocusDistanceRange');
        if ($range !== null) {
            return $range;
        }

        $near = $resolver->float('FocusDistanceRangeNear') ?? $resolver->float('FocusDistanceNear');
        $far  = $resolver->float('FocusDistanceRangeFar') ?? $resolver->float('FocusDistanceFar');

        $values = [];
        if ($near !== null) {
            $values[] = $near;
        }

        if ($far !== null) {
            $values[] = $far;
        }

        return $values !== [] ? $values : null;
    }

    private function stringOrNumeric(QuickTimeResolver $resolver, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $resolver->string($key);
            if ($value !== null && $value !== '') {
                return $value;
            }

            $intValue = $resolver->int($key);
            if ($intValue !== null) {
                return (string) $intValue;
            }

            $floatValue = $resolver->float($key);
            if ($floatValue !== null) {
                return (string) $floatValue;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $map
     */
    private function enumeratedValue(QuickTimeResolver $resolver, array $map, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $resolver->string($key);
            if ($value !== null && $value !== '') {
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                if (is_numeric($trimmed)) {
                    $code = (int) $trimmed;

                    return $map[$code] ?? $trimmed;
                }

                return $trimmed;
            }

            $code = $resolver->int($key);
            if ($code !== null) {
                return $map[$code] ?? (string) $code;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    private function flags(QuickTimeResolver $resolver): array
    {
        $flags = [];
        foreach (self::FLAG_KEYS as $key => $normalized) {
            $value = $resolver->bool($key);
            if ($value !== null) {
                $flags[$normalized] = $value;
            }
        }

        return $flags;
    }
}
