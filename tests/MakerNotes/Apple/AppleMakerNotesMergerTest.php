<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleAutoExposure;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleAutoFocus;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCameraCapture;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCaptureIdentity;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleLivePhoto;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesMerger;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleNoise;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleSemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Exercises AppleMakerNotesMerger conflict resolution between maker notes and QuickTime data.
 * It verifies maker note values take precedence when both sources provide the same fields.
 * The suite checks that missing maker note values can be filled from QuickTime metadata.
 * This ensures merged Apple metadata remains consistent and deterministic.
 *
 * @internal
 */
#[CoversClass(AppleMakerNotesMerger::class)]
#[UsesClass(AppleAutoExposure::class)]
#[UsesClass(AppleAutoFocus::class)]
#[UsesClass(AppleCameraCapture::class)]
#[UsesClass(AppleCaptureIdentity::class)]
#[UsesClass(AppleHdr::class)]
#[UsesClass(AppleLivePhoto::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleNoise::class)]
#[UsesClass(AppleSemanticStyle::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(QuickTimeMeta::class)]
final class AppleMakerNotesMergerTest extends TestCase
{
    /**
     * Provides maker notes with populated fields alongside conflicting QuickTime values.
     * Ensures merge keeps maker note values for populated properties and preserves record metadata.
     *
     * @return void
     */
    #[Test]
    public function mergePrefersMakerNotesValues(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            128,
            str_repeat('1', 40),
            new AppleMakerNotes(
                identity: new AppleCaptureIdentity('maker-note', 'maker-req', 'maker-burst', 'maker-unique', 'maker-photo'),
                hdr: new AppleHdr(2.1, [1.1, 1.2, 1.3], 'HDR'),
                autoExposure: new AppleAutoExposure(true, 0.9, 0.8),
                autoFocus: new AppleAutoFocus(false, 0.5, 1.4, 0.7, 0.4, [0.3, 1.2]),
                noise: new AppleNoise(12.5, 'maker', 0.5),
                semanticStyle: new AppleSemanticStyle('MakerPreset', 0.2, -0.1),
                livePhoto: new AppleLivePhoto(3, 0.5, null, [0.1, 0.2, 0.3]),
                camera: new AppleCameraCapture('Maker Camera', 'Portrait', '2.0', 'High', 'Maker', 5200, [1.0, 0.0, 0.0]),
                flags: ['nightMode' => true],
            ),
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

        $apple = $mapped->apple;

        self::assertNotSame($makerNotes, $mapped);
        self::assertSame('Apple', $mapped->vendor);
        self::assertSame(128, $mapped->length);
        self::assertSame(str_repeat('1', 40), $mapped->sha1);

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('maker-note', $apple->identity?->contentIdentifier);
        self::assertSame('Maker Camera', $apple->camera?->cameraType);
        self::assertSame([1.1, 1.2, 1.3], $apple->hdr?->gain);
        self::assertSame('MakerPreset', $apple->semanticStyle?->preset);
        self::assertSame('Portrait', $apple->camera->imageCaptureType);
        self::assertSame('Maker', $apple->camera->oisMode);
        self::assertSame('maker-burst', $apple->identity->burstUuid);
    }

    /**
     * Supplies a maker notes record with null fields and a QuickTime payload with values.
     * Verifies merge fills missing fields from QuickTime and performs value conversions.
     *
     * @return void
     */
    #[Test]
    public function mergeFillsMissingValuesFromQuickTime(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            64,
            str_repeat('2', 40),
            new AppleMakerNotes(
                identity: null,
                hdr: null,
                autoExposure: null,
                autoFocus: null,
                noise: null,
                semanticStyle: null,
                livePhoto: null,
                camera: null,
                flags: [],
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

        $apple = $mapped->apple;

        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('qt-content', $apple->identity?->contentIdentifier);
        self::assertSame('Quick Camera', $apple->camera?->cameraType);
        self::assertSame([0.9, 1.0, 1.1], $apple->hdr?->gain);
        self::assertSame(1.25, $apple->hdr->headroom);
        self::assertSame(9.5, $apple->noise?->snr);
        self::assertSame(0.45, $apple->autoFocus?->focusPosition);
        self::assertSame(2, $apple->livePhoto?->index);
        self::assertSame(6100, $apple->camera->colorTemperature);
        self::assertSame('QuickPreset', $apple->semanticStyle?->preset);
        self::assertSame([0.2, 0.1, -0.3], $apple->livePhoto->accelerationVector);
        self::assertSame('qt-request', $apple->identity->imageCaptureRequestId);
        self::assertSame('Medium', $apple->camera->qualityHint);
        self::assertSame('1.1', $apple->camera->makerNoteVersion);
        self::assertSame('HDR2', $apple->hdr->imageType);
        self::assertSame([0.5, 1.8], $apple->autoFocus->focusDistanceRange);
        self::assertSame('3', $apple->camera->oisMode);
        self::assertSame('Night Mode', $apple->camera->imageCaptureType);
        self::assertSame('qt-unique', $apple->identity->imageUniqueId);
        self::assertSame('qt-photo', $apple->identity->photoIdentifier);
        self::assertSame(1.2, $apple->autoFocus->measuredDepth);
        self::assertSame(0.6, $apple->autoFocus->confidence);
    }

    /**
     * Merges when no existing maker notes record is provided.
     * Confirms a new record is created with defaults and populated from QuickTime metadata.
     *
     * @return void
     */
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
        self::assertSame('Apple', $mapped->vendor);
        self::assertSame(0, $mapped->length);
        self::assertSame(str_repeat('0', 40), $mapped->sha1);

        $apple = $mapped->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);
        self::assertSame('qt-content', $apple->identity?->contentIdentifier);
        self::assertSame([0.3, -0.2, 0.1], $apple->livePhoto?->accelerationVector);
        self::assertSame('HDR', $apple->hdr?->imageType);
    }

    /**
     * Combines flags from maker notes with flags derived from QuickTime metadata.
     * Ensures existing flags are preserved while additional QuickTime flags are added.
     *
     * @return void
     */
    #[Test]
    public function mergeMergesQuickTimeFlags(): void
    {
        $makerNotes = new MakerNotesRecord(
            'Apple',
            16,
            str_repeat('3', 40),
            new AppleMakerNotes(
                identity: null,
                hdr: null,
                autoExposure: null,
                autoFocus: null,
                noise: null,
                semanticStyle: null,
                livePhoto: null,
                camera: null,
                flags: ['nightMode' => true, 'hdrEnabled' => false],
            ),
        );

        $quickTime = new QuickTimeMeta([
            'NightMode' => false,
            'HdrAuto'   => true,
        ]);

        $merger = new AppleMakerNotesMerger();
        $mapped = $merger->merge($makerNotes, $quickTime);

        self::assertNotNull($mapped);

        $apple = $mapped->apple;
        self::assertInstanceOf(AppleMakerNotes::class, $apple);

        $flags = $apple->flags;
        self::assertTrue($flags['nightMode']);
        self::assertFalse($flags['hdrEnabled']);
        self::assertTrue($flags['hdrAuto']);
    }
}
