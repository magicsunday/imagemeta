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

/**
 * Resolves Apple specific metadata from QuickTime containers.
 */
final readonly class AppleResolver
{
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
        $cameraType         = $cameraTypeString ?? $resolver->int('CameraType');
        $hdrHeadroom        = $resolver->float('HdrHeadroom') ?? $resolver->float('HDRHeadroom');
        $hdrGain            = $this->floatList($resolver, 'HdrGain', 'HDRGain');
        $snr                = $resolver->float('SNRSetting') ?? $resolver->float('SNR');
        $focusPosition      = $resolver->float('FocusPosition');
        $livePhotoIndex     = $resolver->int('LivePhotoVideoIndex');
        $colorTemperature   = $resolver->int('ColorTemperature');
        $semanticPreset     = $resolver->string('SemanticStylePreset');
        $semanticWarmth     = $resolver->float('SemanticStyleWarmth');
        $semanticTone       = $resolver->float('SemanticStyleTone');
        $accelerationVector = $this->floatList($resolver, 'AccelerationVector');
        $flags              = $this->flags($resolver);

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
            $colorTemperature,
            $semanticPreset,
            $semanticWarmth,
            $semanticTone,
            $flags,
            $accelerationVector,
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
