<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Factory\Structured;

use MagicSunday\ImageMeta\Exif\Converters\ApexConverter;
use MagicSunday\ImageMeta\Exif\Converters\ComponentsConverter;
use MagicSunday\ImageMeta\Exif\Converters\ConverterFactory;
use MagicSunday\ImageMeta\Exif\Converters\EnumConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsCoordinateConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsDirectionConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsTimestampConverter;
use MagicSunday\ImageMeta\Exif\Converters\GpsUnitConverter;
use MagicSunday\ImageMeta\Exif\Converters\MatrixConverter;
use MagicSunday\ImageMeta\Exif\Converters\NumericConverter;
use MagicSunday\ImageMeta\Exif\Converters\RationalConverter;
use MagicSunday\ImageMeta\Exif\Converters\StringConverter;
use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\Reader\SceneModeReader;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMapping;
use MagicSunday\ImageMeta\Exif\Reconciliation\ExifXmpMappingRegistry;
use MagicSunday\ImageMeta\Exif\Reconciliation\XmpFallbackResolver;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\Structured\SceneFactory;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Riff\RiffInfoLookup;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Value\Enum\LightSource;
use MagicSunday\ImageMeta\Value\Enum\SceneCaptureType;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SubjectDistanceRange;
use MagicSunday\ImageMeta\Value\Scene;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Exercises SceneFactory for mapping EXIF and maker note inputs to Scene values.
 * It verifies scene capture type, scene type, light source, and distance range conversions.
 * The suite covers face count and HDR/night mode flags from maker notes.
 * This ensures scene metadata is normalized consistently for structured output.
 *
 * @internal
 */
#[CoversClass(SceneFactory::class)]
#[UsesClass(ApexConverter::class)]
#[UsesClass(ComponentsConverter::class)]
#[UsesClass(ConverterFactory::class)]
#[UsesClass(EnumConverter::class)]
#[UsesClass(GpsConverter::class)]
#[UsesClass(GpsCoordinateConverter::class)]
#[UsesClass(GpsDirectionConverter::class)]
#[UsesClass(GpsTimestampConverter::class)]
#[UsesClass(GpsUnitConverter::class)]
#[UsesClass(MatrixConverter::class)]
#[UsesClass(NumericConverter::class)]
#[UsesClass(RationalConverter::class)]
#[UsesClass(StringConverter::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(IfdValueReader::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(SceneModeReader::class)]
#[UsesClass(ExifXmpMapping::class)]
#[UsesClass(ExifXmpMappingRegistry::class)]
#[UsesClass(XmpFallbackResolver::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(AppleHdr::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(QuickTimeLookup::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(RiffInfoLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(Scene::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class SceneFactoryTest extends TestCase
{
    /**
     * Supplies EXIF scene tags and a face count to SceneFactory.
     * Verifies the scene value object maps capture type, scene type, light, and distance range.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $scene = $this->createScene(
            exifDoc: $this->parsedExif(
                sceneCaptureType: SceneCaptureType::Standard,
                sceneType: SceneType::DirectlyPhotographedImage->value,
                lightSource: LightSource::Daylight,
                subjectDistanceRange: SubjectDistanceRange::Close,
            ),
            faceCount: 2,
        );

        self::assertSame(SceneCaptureType::Standard, $scene->type);
        self::assertSame(SceneType::DirectlyPhotographedImage, $scene->sceneType);
        self::assertSame(LightSource::Daylight, $scene->light);
        self::assertSame(2, $scene->faceCount);
        self::assertSame(SubjectDistanceRange::Close, $scene->subjectDistanceRange);
    }

    /**
     * Uses Apple maker notes with a positive HDR headroom value.
     * Ensures SceneFactory flags the scene as HDR based on headroom.
     */
    #[Test]
    public function detectsHdrSceneFromAppleHeadroom(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: new AppleHdr(headroom: 2.5, gain: null, imageType: null),
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: [],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(makerNotes: $makerNotes);

        self::assertTrue($scene->hdrScene);
    }

    /**
     * Supplies QuickTime metadata with the NightMode flag enabled.
     * Confirms the scene value object reports night mode as true.
     */
    #[Test]
    public function detectsNightModeFromQuickTime(): void
    {
        $scene = $this->createScene(
            quickTime: new QuickTimeMeta([
                'NightMode' => true,
            ]),
        );

        self::assertTrue($scene->nightMode);
    }

    /**
     * Uses Apple maker note flags to indicate HDR capture.
     * Verifies hdrScene is enabled even without HDR headroom data.
     */
    #[Test]
    public function detectsHdrFromAppleFlags(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: null,
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: ['hdrEnabled' => true],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(makerNotes: $makerNotes);

        self::assertTrue($scene->hdrScene);
    }

    /**
     * Supplies an invalid SceneCaptureType enum backing value.
     * Verifies the factory returns null for the type field.
     */
    #[Test]
    public function returnsNullForInvalidSceneCaptureType(): void
    {
        $scene = $this->createScene(
            exifDoc: $this->parsedExifFromEntries([
                ExifTag::SCENE_CAPTURE_TYPE => new IfdEntry(
                    ExifTag::SCENE_CAPTURE_TYPE,
                    3,
                    1,
                    255,
                ),
            ]),
        );

        self::assertNull($scene->type);
    }

    /**
     * Supplies an invalid LightSource enum backing value.
     * Verifies the factory returns null for the light field.
     */
    #[Test]
    public function returnsNullForInvalidLightSource(): void
    {
        $scene = $this->createScene(
            exifDoc: $this->parsedExifFromEntries([
                ExifTag::LIGHT_SOURCE => new IfdEntry(
                    ExifTag::LIGHT_SOURCE,
                    3,
                    1,
                    254,
                ),
            ]),
        );

        self::assertNull($scene->light);
    }

    /**
     * Supplies an Apple HDR imageType label that the factory already resolved.
     * Asserts the QuickTime HDRImageType fallback is NOT consulted when imageType is present.
     */
    #[Test]
    public function prefersAppleHdrImageTypeOverQuickTimeFallback(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: new AppleHdr(headroom: null, gain: null, imageType: 'HDR'),
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: [],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(
            quickTime: new QuickTimeMeta([
                'HDRImageType' => 'non-hdr',
            ]),
            makerNotes: $makerNotes,
        );

        self::assertTrue($scene->hdrScene);
    }

    /**
     * Supplies a non-HDR label string in Apple maker notes.
     * Asserts that hdrScene is NOT set to true when the label does not start with "HDR".
     */
    #[Test]
    public function nonHdrLabelDoesNotFlagHdrScene(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: new AppleHdr(headroom: null, gain: null, imageType: 'SDR'),
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: [],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(makerNotes: $makerNotes);

        self::assertNull($scene->hdrScene);
    }

    /**
     * Supplies an Apple HDR headroom of exactly zero.
     * Asserts that hdrScene is NOT flagged because headroom must be strictly positive.
     */
    #[Test]
    public function zeroHeadroomDoesNotFlagHdrScene(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: new AppleHdr(headroom: 0.0, gain: null, imageType: null),
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: [],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(makerNotes: $makerNotes);

        self::assertNull($scene->hdrScene);
    }

    /**
     * Supplies null headroom with no Apple flags or HDR label.
     * Asserts that hdrScene remains null because the null headroom guard must reject it.
     */
    #[Test]
    public function nullHeadroomWithoutFlagsDoesNotFlagHdrScene(): void
    {
        $apple = new AppleMakerNotes(
            identity: null,
            hdr: new AppleHdr(headroom: null, gain: null, imageType: null),
            autoExposure: null,
            autoFocus: null,
            noise: null,
            semanticStyle: null,
            livePhoto: null,
            camera: null,
            flags: [],
        );

        $makerNotes = new MakerNotesRecord(
            vendor: 'APPLE',
            length: 0,
            sha1: str_repeat('0', 40),
            apple: $apple,
        );

        $scene = $this->createScene(makerNotes: $makerNotes);

        self::assertNull($scene->hdrScene);
    }

    /**
     * Provides both EXIF and XMP values for sceneCaptureType.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForSceneCaptureType(): void
    {
        $entries = [
            ExifTag::SCENE_CAPTURE_TYPE => new IfdEntry(ExifTag::SCENE_CAPTURE_TYPE, 3, 1, SceneCaptureType::Landscape->value),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}SceneCaptureType' => (string) SceneCaptureType::NightScene->value,
        ];

        $scene = $this->createSceneWithExifAndXmp($entries, $xmpData);

        self::assertSame(SceneCaptureType::Landscape, $scene->type);
    }

    /**
     * Provides both EXIF and XMP values for sceneType.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForSceneType(): void
    {
        $entries = [
            ExifTag::SCENE_TYPE => new IfdEntry(ExifTag::SCENE_TYPE, 7, 1, SceneType::DirectlyPhotographedImage->value),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}SceneType' => (string) SceneType::NotDefined->value,
        ];

        $scene = $this->createSceneWithExifAndXmp($entries, $xmpData);

        self::assertSame(SceneType::DirectlyPhotographedImage, $scene->sceneType);
    }

    /**
     * Provides both EXIF and XMP values for lightSource.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForLightSource(): void
    {
        $entries = [
            ExifTag::LIGHT_SOURCE => new IfdEntry(ExifTag::LIGHT_SOURCE, 3, 1, LightSource::Daylight->value),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}LightSource' => (string) LightSource::Tungsten->value,
        ];

        $scene = $this->createSceneWithExifAndXmp($entries, $xmpData);

        self::assertSame(LightSource::Daylight, $scene->light);
    }

    /**
     * Provides both EXIF and XMP values for subjectDistanceRange.
     * Asserts the EXIF value wins over the XMP fallback.
     */
    #[Test]
    public function exifTakesPriorityOverXmpForSubjectDistanceRange(): void
    {
        $entries = [
            ExifTag::SUBJECT_DISTANCE_RANGE => new IfdEntry(ExifTag::SUBJECT_DISTANCE_RANGE, 3, 1, SubjectDistanceRange::Macro->value),
        ];

        $xmpData = [
            '{http://ns.adobe.com/exif/1.0/}SubjectDistanceRange' => (string) SubjectDistanceRange::Distant->value,
        ];

        $scene = $this->createSceneWithExifAndXmp($entries, $xmpData);

        self::assertSame(SubjectDistanceRange::Macro, $scene->subjectDistanceRange);
    }

    /**
     * Creates Metadata without EXIF, QuickTime, or maker notes scene data.
     * Ensures all scene fields remain null when no inputs are available.
     */
    #[Test]
    public function createsWithNullMetadata(): void
    {
        $scene = $this->createScene();

        self::assertNull($scene->type);
        self::assertNull($scene->sceneType);
        self::assertNull($scene->light);
        self::assertNull($scene->faceCount);
        self::assertNull($scene->hdrScene);
        self::assertNull($scene->nightMode);
        self::assertNull($scene->subjectDistanceRange);
    }

    private function createScene(
        ?ParsedExif $exifDoc = null,
        ?QuickTimeMeta $quickTime = null,
        ?MakerNotesRecord $makerNotes = null,
        ?int $faceCount = null,
    ): Scene {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: $quickTime,
            exifDoc: $exifDoc,
            makerNotes: $makerNotes,
        );

        $apple = $makerNotes?->apple;

        if (!$apple instanceof AppleMakerNotes) {
            $apple = AppleMakerNotes::empty();
        }

        return new SceneFactory()->create($metadata, $apple, $faceCount);
    }

    private function parsedExif(
        ?SceneCaptureType $sceneCaptureType,
        ?int $sceneType,
        ?LightSource $lightSource,
        ?SubjectDistanceRange $subjectDistanceRange,
    ): ParsedExif {
        $exifEntries = [];

        if ($sceneCaptureType instanceof SceneCaptureType) {
            $exifEntries[ExifTag::SCENE_CAPTURE_TYPE] = new IfdEntry(
                ExifTag::SCENE_CAPTURE_TYPE,
                3,
                1,
                $sceneCaptureType->value,
            );
        }

        if ($sceneType !== null) {
            $exifEntries[ExifTag::SCENE_TYPE] = new IfdEntry(
                ExifTag::SCENE_TYPE,
                7,
                1,
                $sceneType,
            );
        }

        if ($lightSource instanceof LightSource) {
            $exifEntries[ExifTag::LIGHT_SOURCE] = new IfdEntry(
                ExifTag::LIGHT_SOURCE,
                3,
                1,
                $lightSource->value,
            );
        }

        if ($subjectDistanceRange instanceof SubjectDistanceRange) {
            $exifEntries[ExifTag::SUBJECT_DISTANCE_RANGE] = new IfdEntry(
                ExifTag::SUBJECT_DISTANCE_RANGE,
                3,
                1,
                $subjectDistanceRange->value,
            );
        }

        return $this->parsedExifFromEntries($exifEntries);
    }

    /**
     * @param array<int, IfdEntry>  $entries EXIF IFD entries keyed by tag.
     * @param array<string, string> $xmpData XMP data keyed by Clark notation.
     */
    private function createSceneWithExifAndXmp(array $entries, array $xmpData): Scene
    {
        $parsedExif = $this->parsedExifFromEntries($entries);
        $xmpDoc     = new XmpDocument($xmpData);

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
            xmpDoc: $xmpDoc,
        );

        $apple = AppleMakerNotes::empty();

        return (new SceneFactory())->create($metadata, $apple);
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromEntries(array $exifEntries): ParsedExif
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
