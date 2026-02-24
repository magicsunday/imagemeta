<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use MagicSunday\ImageMeta\Model\Iptc\IptcDocument;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffDataReferenceMap;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReference;
use MagicSunday\ImageMeta\Model\IsoBmff\IsoBmffItemReferenceMap;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use MagicSunday\ImageMeta\Model\Mpf\MpfDocument;
use MagicSunday\ImageMeta\Model\Mpf\MpfEntry;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Model\Xmp\XmpLanguageAlternative;
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use MagicSunday\ImageMeta\Value\AudioClip;
use MagicSunday\ImageMeta\Value\AudioClips;
use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\DeviceSettingDescription;
use MagicSunday\ImageMeta\Value\Keywords;
use MagicSunday\ImageMeta\Value\MultiPicture;
use MagicSunday\ImageMeta\Value\MultiPictureEntry;
use MagicSunday\ImageMeta\Value\Oecf;
use MagicSunday\ImageMeta\Value\Region;
use MagicSunday\ImageMeta\Value\RegionCollection;
use MagicSunday\ImageMeta\Value\SourceExposureTimes;
use MagicSunday\ImageMeta\Value\SpatialFrequencyResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that readonly value objects with array properties are immune
 * to external mutation of the arrays passed to their constructors.
 *
 * @internal
 */
#[CoversClass(AudioClips::class)]
#[CoversClass(CfaPattern::class)]
#[CoversClass(DeviceSettingDescription::class)]
#[CoversClass(IptcDocument::class)]
#[CoversClass(Keywords::class)]
#[CoversClass(Metadata::class)]
#[CoversClass(MpfAttributes::class)]
#[CoversClass(MpfDocument::class)]
#[CoversClass(MultiPicture::class)]
#[CoversClass(Oecf::class)]
#[CoversClass(QuickTimeMeta::class)]
#[CoversClass(RegionCollection::class)]
#[CoversClass(SourceExposureTimes::class)]
#[CoversClass(SpatialFrequencyResponse::class)]
#[CoversClass(XmpDocument::class)]
#[CoversClass(XmpLanguageAlternative::class)]
#[CoversClass(XmpStructuredValue::class)]
#[UsesClass(AudioClip::class)]
#[UsesClass(IsoBmffDataReference::class)]
#[UsesClass(IsoBmffDataReferenceMap::class)]
#[UsesClass(IsoBmffItemReference::class)]
#[UsesClass(IsoBmffItemReferenceMap::class)]
#[UsesClass(MultiPictureEntry::class)]
#[UsesClass(MpfEntry::class)]
final class DefensiveCopyTest extends TestCase
{
    #[Test]
    public function audioClipsIsolatesClipsArray(): void
    {
        $clips = [new AudioClip('wav', 1, 44100, 16, 'data', '1.0')];
        $obj   = new AudioClips($clips);

        $clips[] = new AudioClip('mp3', 2, 48000, 24, 'more', '1.0');

        self::assertCount(1, $obj->clips);
    }

    #[Test]
    public function cfaPatternIsolatesColorsArray(): void
    {
        $pattern = CfaPattern::fromComponents(2, 2, [0, 1, 1, 2]);

        self::assertNotNull($pattern);
        self::assertCount(4, $pattern->colors);
    }

    #[Test]
    public function keywordsIsolatesFlatAndHierarchicalArrays(): void
    {
        $flat         = ['a', 'b'];
        $hierarchical = ['x|y'];
        $obj          = new Keywords($flat, $hierarchical);

        $flat[]         = 'c';
        $hierarchical[] = 'z|w';

        self::assertCount(2, $obj->flat);
        self::assertNotNull($obj->hierarchical);
        self::assertCount(1, $obj->hierarchical);
    }

    #[Test]
    public function keywordsIsolatesNullHierarchical(): void
    {
        $flat = ['a'];
        $obj  = new Keywords($flat, null);

        $flat[] = 'b';

        self::assertCount(1, $obj->flat);
        self::assertNull($obj->hierarchical);
    }

    #[Test]
    public function regionCollectionIsolatesItemsArray(): void
    {
        /** @var list<Region> $items */
        $items = [];
        $obj   = new RegionCollection($items);

        $items[] = 'fake';

        self::assertCount(0, $obj->items);
    }

    #[Test]
    public function deviceSettingDescriptionIsolatesSettingsArray(): void
    {
        $settings = ['ISO 100', 'f/2.8'];
        $obj      = new DeviceSettingDescription(2, 1, $settings);

        $settings[] = 'AWB';

        self::assertCount(2, $obj->settings);
    }

    #[Test]
    public function sourceExposureTimesIsolatesSequencesArray(): void
    {
        $sequences = [[1.0, 2.0]];
        $obj       = new SourceExposureTimes(null, null, null, null, null, null, null, null, $sequences);

        $sequences[] = [3.0];

        self::assertCount(1, $obj->sequences);
    }

    #[Test]
    public function oecfIsolatesArrayProperties(): void
    {
        $columnLabels = ['col1'];
        $rowLabels    = ['row1'];
        $values       = [[1.0]];
        $obj          = new Oecf(1, 1, $columnLabels, $rowLabels, $values);

        $columnLabels[] = 'col2';
        $rowLabels[]    = 'row2';
        $values[]       = [2.0];

        self::assertCount(1, $obj->columnLabels);
        self::assertCount(1, $obj->rowLabels);
        self::assertCount(1, $obj->values);
    }

    #[Test]
    public function spatialFrequencyResponseIsolatesArrayProperties(): void
    {
        $frequencies = ['10lp/mm'];
        $values      = [[0.5]];
        $obj         = new SpatialFrequencyResponse(1, 1, $frequencies, $values);

        $frequencies[] = '20lp/mm';
        $values[]      = [0.8];

        self::assertCount(1, $obj->spatialFrequencies);
        self::assertCount(1, $obj->values);
    }

    #[Test]
    public function multiPictureIsolatesArrayProperties(): void
    {
        $entries = [new MultiPictureEntry(0x030000, 2048, 1024, 0, 0)];
        $angle   = [['numerator' => 1, 'denominator' => 2]];
        $axis    = [['numerator' => 3, 'denominator' => 4]];
        $obj     = new MultiPicture('0100', 1, $entries, null, null, null, $angle, $axis);

        $entries[] = new MultiPictureEntry(0, 0, 0, 0, 0);
        $angle[]   = ['numerator' => 5, 'denominator' => 6];
        $axis[]    = ['numerator' => 7, 'denominator' => 8];

        self::assertCount(1, $obj->entries);
        self::assertNotNull($obj->panoramaAngle);
        self::assertCount(1, $obj->panoramaAngle);
        self::assertNotNull($obj->panoramaAxis);
        self::assertCount(1, $obj->panoramaAxis);
    }

    #[Test]
    public function multiPictureIsolatesNullableArrays(): void
    {
        $entries = [new MultiPictureEntry(0, 0, 0, 0, 0)];
        $obj     = new MultiPicture('0100', 1, $entries, null, null, null, null, null);

        $entries[] = new MultiPictureEntry(0, 0, 0, 0, 0);

        self::assertCount(1, $obj->entries);
        self::assertNull($obj->panoramaAngle);
        self::assertNull($obj->panoramaAxis);
    }

    #[Test]
    public function metadataIsolatesListArrayProperties(): void
    {
        $exifBlobs = ['blob1'];
        $xmpBlobs  = ['xmp1'];
        $obj       = new Metadata($exifBlobs, null, xmpBlobs: $xmpBlobs);

        $exifBlobs[] = 'blob2';
        $xmpBlobs[]  = 'xmp2';

        self::assertCount(1, $obj->exifBlobs);
        self::assertCount(1, $obj->xmpBlobs);
    }

    #[Test]
    public function xmpDocumentIsolatesArrayProperties(): void
    {
        $data     = ['{ns}prop' => 'value'];
        $prefixes = ['ns' => 'p'];
        $obj      = new XmpDocument($data, $prefixes);

        $data['{ns}other'] = 'other';
        $prefixes['ns2']   = 'p2';

        self::assertCount(1, $obj->data);
        self::assertCount(1, $obj->namespacePrefixes);
    }

    #[Test]
    public function quickTimeMetaIsolatesArrayProperties(): void
    {
        $keys = ['key1' => 'value1'];
        $obj  = new QuickTimeMeta($keys);

        $keys['key2'] = 'value2';

        self::assertCount(1, $obj->keys);
    }

    #[Test]
    public function xmpLanguageAlternativeIsolatesEntriesArray(): void
    {
        $entries = [['lang' => 'en', 'value' => 'Hello']];
        $obj     = new XmpLanguageAlternative($entries);

        $entries[] = ['lang' => 'de', 'value' => 'Hallo'];

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function xmpStructuredValueIsolatesFieldsArray(): void
    {
        $fields = ['{ns}field' => 'value'];
        $obj    = new XmpStructuredValue($fields);

        $fields['{ns}other'] = 'other';

        self::assertCount(1, $obj->fields);
    }

    #[Test]
    public function iptcDocumentIsolatesDatasetsArray(): void
    {
        $datasets = ['2:5' => ['title']];
        $obj      = new IptcDocument($datasets);

        $datasets['2:120'] = ['caption'];

        self::assertCount(1, $obj->datasets);
    }

    #[Test]
    public function mpfDocumentIsolatesEntriesArray(): void
    {
        $entries = [new MpfEntry(0, 1024, 0, 0, 0)];
        $obj     = new MpfDocument('0100', 1, $entries, null);

        $entries[] = new MpfEntry(0, 2048, 512, 0, 0);

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function mpfAttributesIsolatesNullableArrays(): void
    {
        $angle = [['numerator' => 1, 'denominator' => 2]];
        $axis  = [['numerator' => 3, 'denominator' => 4]];
        $obj   = new MpfAttributes(null, null, null, $angle, $axis, []);

        $angle[] = ['numerator' => 5, 'denominator' => 6];
        $axis[]  = ['numerator' => 7, 'denominator' => 8];

        self::assertNotNull($obj->panoramaAngle);
        self::assertCount(1, $obj->panoramaAngle);
        self::assertNotNull($obj->panoramaAxis);
        self::assertCount(1, $obj->panoramaAxis);
    }
}
