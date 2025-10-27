<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Resolver;

use MagicSunday\ImageMeta\Curate\Resolver\AppleResolver;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppleResolverTest extends TestCase
{
    #[Test]
    public function resolveExtractsExtendedQuickTimeMetadata(): void
    {
        $resolver = new AppleResolver();
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'qt-content',
            'CameraType'                          => 'Wide',
            'HdrHeadroom'                         => 2.3,
            'HdrGain'                             => '1.1 1.2 1.3',
            'SNRSetting'                          => 17.5,
            'FocusPosition'                       => 0.42,
            'LivePhotoVideoIndex'                 => 6,
            'ColorTemperature'                    => 5200,
            'SemanticStylePreset'                 => 'ResolverPreset',
            'SemanticStyleWarmth'                 => 0.25,
            'SemanticStyleTone'                   => -0.2,
            'MakerNoteVersion'                    => '1.2',
            'HDRImageType'                        => 3,
            'BurstUUID'                           => 'resolver-burst',
            'FocusDistanceRange'                  => '0.7 2.4',
            'OISMode'                             => 5,
            'ImageCaptureType'                    => 2,
            'ImageUniqueID'                       => 'resolver-unique',
            'PhotoIdentifier'                     => 'resolver-photo',
            'AFMeasuredDepth'                     => 1.8,
            'AFConfidence'                        => 0.6,
            'LivePhotoAuto'                       => true,
        ]);

        $apple = $resolver->resolve($quickTime);

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('qt-content', $apple->contentIdentifier);
        self::assertSame('Wide', $apple->cameraType);
        self::assertSame([1.1, 1.2, 1.3], $apple->hdrGain);
        self::assertEqualsWithDelta(2.3, $apple->hdrHeadroom, 1e-12);
        self::assertEqualsWithDelta(17.5, $apple->snr, 1e-12);
        self::assertEqualsWithDelta(0.42, $apple->focusPosition, 1e-12);
        self::assertSame(6, $apple->livePhotoIndex);
        self::assertSame(5200, $apple->colorTemperature);
        self::assertSame('ResolverPreset', $apple->semanticStylePreset);
        self::assertEqualsWithDelta(0.25, $apple->semanticStyleWarmth, 1e-12);
        self::assertEqualsWithDelta(-0.2, $apple->semanticStyleTone, 1e-12);
        self::assertTrue($apple->flags['livePhotoAuto']);
        self::assertSame('1.2', $apple->makerNoteVersion);
        self::assertSame('HDR Image', $apple->hdrImageType);
        self::assertSame('resolver-burst', $apple->burstUuid);
        self::assertSame([0.7, 2.4], $apple->focusDistanceRange);
        self::assertSame('5', $apple->oisMode);
        self::assertSame('Portrait', $apple->imageCaptureType);
        self::assertSame('resolver-unique', $apple->imageUniqueId);
        self::assertSame('resolver-photo', $apple->photoIdentifier);
        self::assertEqualsWithDelta(1.8, $apple->afMeasuredDepth, 1e-12);
        self::assertEqualsWithDelta(0.6, $apple->afConfidence, 1e-12);
    }

    #[Test]
    public function resolveReturnsNullWhenNoValuesPresent(): void
    {
        $resolver = new AppleResolver();

        self::assertNull($resolver->resolve(null));
        self::assertNull($resolver->resolve(new QuickTimeMeta([])));
    }
}
