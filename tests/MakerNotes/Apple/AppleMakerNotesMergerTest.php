<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * @covers \MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger
 */
final class AppleMakerNotesMergerTest extends TestCase
{
    #[Test]
    public function mergePrefersMakerNotesValues(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            128,
            str_repeat('1', 40),
            new AppleMakerNotes(
                contentIdentifier: 'maker-note',
                cameraType: 'Maker Camera',
                hdrHeadroom: 2.1,
                hdrGain: [1.1, 1.2, 1.3],
                snr: 12.5,
                aeStable: true,
                aeTarget: 0.9,
                aeAverage: 0.8,
                afStable: false,
                afPerformance: 0.5,
                signalToNoiseRatioType: 'maker',
                luminanceNoiseAmplitude: 0.5,
                focusPosition: 0.4,
                livePhotoIndex: 3,
                colorTemperature: 5200,
                semanticStylePreset: 'MakerPreset',
                semanticStyleWarmth: 0.2,
                semanticStyleTone: -0.1,
                flags: ['nightMode' => true],
                accelerationVector: [0.1, 0.2, 0.3],
                imageCaptureRequestId: 'maker-req',
                qualityHint: 'High',
                colorCorrectionMatrix: [1.0, 0.0, 0.0],
                livePhotoTime: 0.5,
                runTime: null,
                makerNoteVersion: '2.0',
                hdrImageType: 'HDR',
                burstUuid: 'maker-burst',
                focusDistanceRange: [0.3, 1.2],
                oisMode: 'Maker',
                imageCaptureType: 'Portrait',
                imageUniqueId: 'maker-unique',
                photoIdentifier: 'maker-photo',
                afMeasuredDepth: 1.4,
                afConfidence: 0.7,
            ),
            false,
        );

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'qt-content',
            'CameraType'                          => 'QuickTime Camera',
            'HdrHeadroom'                         => 1.0,
            'HdrGain'                             => '0.4 0.5 0.6',
            'SNRSetting'                          => 8.0,
            'LivePhotoVideoIndex'                 => 5,
            'ColorTemperature'                    => 6300,
            'SemanticStylePreset'                 => 'QuickPreset',
            'SemanticStyleWarmth'                 => 0.1,
            'SemanticStyleTone'                   => 0.05,
            'OISMode'                             => 2,
            'ImageCaptureType'                    => 6,
        ]);

        $merger = new AppleMakerNotesMerger();
        $mapped = $merger->merge($makerNotes, $quickTime);

        self::assertNotNull($mapped);

        $apple = $mapped->apple();

        self::assertNotSame($makerNotes, $mapped);
        self::assertSame('Apple', $mapped->vendor());
        self::assertSame(128, $mapped->length());
        self::assertSame(str_repeat('1', 40), $mapped->sha1());

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('maker-note', $apple->contentIdentifier);
        self::assertSame('Maker Camera', $apple->cameraType);
        self::assertSame([1.1, 1.2, 1.3], $apple->hdrGain);
        self::assertSame('MakerPreset', $apple->semanticStylePreset);
        self::assertSame('Portrait', $apple->imageCaptureType);
        self::assertSame('Maker', $apple->oisMode);
        self::assertSame('maker-burst', $apple->burstUuid);
    }

    #[Test]
    public function mergeFillsMissingValuesFromQuickTime(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            64,
            str_repeat('2', 40),
            new AppleMakerNotes(
                contentIdentifier: null,
                cameraType: null,
                hdrHeadroom: null,
                hdrGain: null,
                snr: null,
                aeStable: null,
                aeTarget: null,
                aeAverage: null,
                afStable: null,
                afPerformance: null,
                signalToNoiseRatioType: null,
                luminanceNoiseAmplitude: null,
                focusPosition: null,
                livePhotoIndex: null,
                colorTemperature: null,
                semanticStylePreset: null,
                semanticStyleWarmth: null,
                semanticStyleTone: null,
                flags: [],
                accelerationVector: null,
            ),
        );

        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'qt-content',
            'CameraType'                          => 'Quick Camera',
            'HdrHeadroom'                         => 1.25,
            'HdrGain'                             => '0.9 1.0 1.1',
            'SNRSetting'                          => 9.5,
            'FocusPosition'                       => 0.45,
            'LivePhotoVideoIndex'                 => 2,
            'ColorTemperature'                    => 6100,
            'SemanticStylePreset'                 => 'QuickPreset',
            'SemanticStyleWarmth'                 => 0.25,
            'SemanticStyleTone'                   => -0.05,
            'AccelerationVector'                  => '0.2 0.1 -0.3',
            'ImageCaptureRequestID'               => 'qt-request',
            'QualityHint'                         => 'Medium',
            'ColorCorrectionMatrix'               => '1 0 0',
            'MakerNoteVersion'                    => '1.1',
            'HDRImageType'                        => 2,
            'BurstUUID'                           => 'qt-burst',
            'FocusDistanceRange'                  => '0.5 1.8',
            'OISMode'                             => 3,
            'ImageCaptureType'                    => 6,
            'ImageUniqueID'                       => 'qt-unique',
            'PhotoIdentifier'                     => 'qt-photo',
            'AFMeasuredDepth'                     => 1.2,
            'AFConfidence'                        => 0.6,
        ]);

        $merger = new AppleMakerNotesMerger();
        $mapped = $merger->merge($makerNotes, $quickTime);

        self::assertNotNull($mapped);

        $apple = $mapped->apple();

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('qt-content', $apple->contentIdentifier);
        self::assertSame('Quick Camera', $apple->cameraType);
        self::assertSame([0.9, 1.0, 1.1], $apple->hdrGain);
        self::assertSame(1.25, $apple->hdrHeadroom);
        self::assertSame(9.5, $apple->snr);
        self::assertSame(0.45, $apple->focusPosition);
        self::assertSame(2, $apple->livePhotoIndex);
        self::assertSame(6100, $apple->colorTemperature);
        self::assertSame('QuickPreset', $apple->semanticStylePreset);
        self::assertSame([0.2, 0.1, -0.3], $apple->accelerationVector);
        self::assertSame('qt-request', $apple->imageCaptureRequestId);
        self::assertSame('Medium', $apple->qualityHint);
        self::assertSame('1.1', $apple->makerNoteVersion);
        self::assertSame('HDR2', $apple->hdrImageType);
        self::assertSame([0.5, 1.8], $apple->focusDistanceRange);
        self::assertSame('3', $apple->oisMode);
        self::assertSame('Night Mode', $apple->imageCaptureType);
        self::assertSame('qt-unique', $apple->imageUniqueId);
        self::assertSame('qt-photo', $apple->photoIdentifier);
        self::assertSame(1.2, $apple->afMeasuredDepth);
        self::assertSame(0.6, $apple->afConfidence);
    }

    #[Test]
    public function mergeCreatesMetadataFromQuickTimeWhenAbsent(): void
    {
        $quickTime = new QuickTimeMeta([
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'qt-content',
            'HDRImageType'                        => 1,
            'AccelerationVector'                  => '0.3 -0.2 0.1',
        ]);

        $merger = new AppleMakerNotesMerger();
        $mapped = $merger->merge(null, $quickTime);

        self::assertNotNull($mapped);
        self::assertSame('Apple', $mapped->vendor());
        self::assertSame(0, $mapped->length());
        self::assertSame(str_repeat('0', 40), $mapped->sha1());

        $apple = $mapped->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('qt-content', $apple->contentIdentifier);
        self::assertSame([0.3, -0.2, 0.1], $apple->accelerationVector);
        self::assertSame('HDR', $apple->hdrImageType);
    }

    #[Test]
    public function mergeMergesQuickTimeFlags(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            16,
            str_repeat('3', 40),
            new AppleMakerNotes(
                contentIdentifier: null,
                cameraType: null,
                hdrHeadroom: null,
                hdrGain: null,
                snr: null,
                aeStable: null,
                aeTarget: null,
                aeAverage: null,
                afStable: null,
                afPerformance: null,
                signalToNoiseRatioType: null,
                luminanceNoiseAmplitude: null,
                focusPosition: null,
                livePhotoIndex: null,
                colorTemperature: null,
                semanticStylePreset: null,
                semanticStyleWarmth: null,
                semanticStyleTone: null,
                flags: ['nightMode' => true, 'hdrEnabled' => false],
                accelerationVector: null,
            ),
        );

        $quickTime = new QuickTimeMeta([
            'NightMode' => false,
            'HdrAuto'   => true,
        ]);

        $merger = new AppleMakerNotesMerger();
        $mapped = $merger->merge($makerNotes, $quickTime);

        self::assertNotNull($mapped);

        $apple = $mapped->apple();
        self::assertInstanceOf(AppleMakerNotes::class, $apple);

        $flags = $apple->flags;
        self::assertTrue($flags['nightMode']);
        self::assertFalse($flags['hdrEnabled']);
        self::assertTrue($flags['hdrAuto']);
    }
}
