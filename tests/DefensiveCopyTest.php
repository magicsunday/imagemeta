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
        $obj = new AudioClips([new AudioClip('wav', 1, 44100, 16, 'data', '1.0')]);

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
        $obj = new Keywords(['a', 'b'], ['x|y']);

        self::assertCount(2, $obj->flat);
        self::assertNotNull($obj->hierarchical);
        self::assertCount(1, $obj->hierarchical);
    }

    #[Test]
    public function keywordsIsolatesNullHierarchical(): void
    {
        $obj = new Keywords(['a'], null);

        self::assertCount(1, $obj->flat);
        self::assertNull($obj->hierarchical);
    }

    #[Test]
    public function regionCollectionIsolatesItemsArray(): void
    {
        /** @var list<Region> $items */
        $items = [];
        $obj   = new RegionCollection($items);

        self::assertCount(0, $obj->items);
    }

    #[Test]
    public function deviceSettingDescriptionIsolatesSettingsArray(): void
    {
        $obj = new DeviceSettingDescription(2, 1, ['ISO 100', 'f/2.8']);

        self::assertCount(2, $obj->settings);
    }

    #[Test]
    public function sourceExposureTimesIsolatesSequencesArray(): void
    {
        $obj = new SourceExposureTimes(null, null, null, null, null, null, null, null, [[1.0, 2.0]]);

        self::assertCount(1, $obj->sequences);
    }

    #[Test]
    public function oecfIsolatesArrayProperties(): void
    {
        $obj = new Oecf(1, 1, ['col1'], ['row1'], [[1.0]]);

        self::assertCount(1, $obj->columnLabels);
        self::assertCount(1, $obj->rowLabels);
        self::assertCount(1, $obj->values);
    }

    #[Test]
    public function spatialFrequencyResponseIsolatesArrayProperties(): void
    {
        $obj = new SpatialFrequencyResponse(1, 1, ['10lp/mm'], [[0.5]]);

        self::assertCount(1, $obj->spatialFrequencies);
        self::assertCount(1, $obj->values);
    }

    #[Test]
    public function multiPictureIsolatesArrayProperties(): void
    {
        $obj = new MultiPicture(
            '0100',
            1,
            [new MultiPictureEntry(0x030000, 2048, 1024, 0, 0, false, false, false, null, null)],
            null,
            null,
            null,
        );

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function multiPictureIsolatesNullableArrays(): void
    {
        $obj = new MultiPicture('0100', 1, [new MultiPictureEntry(0, 0, 0, 0, 0, false, false, false, null, null)], null, null, null);

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function metadataIsolatesListArrayProperties(): void
    {
        $obj = new Metadata(['blob1'], null, xmpBlobs: ['xmp1']);

        self::assertCount(1, $obj->exifBlobs);
        self::assertCount(1, $obj->xmpBlobs);
    }

    #[Test]
    public function xmpDocumentIsolatesArrayProperties(): void
    {
        $obj = new XmpDocument(['{ns}prop' => 'value'], ['ns' => 'p']);

        self::assertCount(1, $obj->data);
        self::assertCount(1, $obj->namespacePrefixes);
    }

    #[Test]
    public function quickTimeMetaIsolatesArrayProperties(): void
    {
        $obj = new QuickTimeMeta(['key1' => 'value1']);

        self::assertCount(1, $obj->keys);
    }

    #[Test]
    public function xmpLanguageAlternativeIsolatesEntriesArray(): void
    {
        $obj = new XmpLanguageAlternative([['lang' => 'en', 'value' => 'Hello']]);

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function xmpStructuredValueIsolatesFieldsArray(): void
    {
        $obj = new XmpStructuredValue(['{ns}field' => 'value']);

        self::assertCount(1, $obj->fields);
    }

    #[Test]
    public function iptcDocumentIsolatesDatasetsArray(): void
    {
        $obj = new IptcDocument(['2:5' => ['title']]);

        self::assertCount(1, $obj->datasets);
    }

    #[Test]
    public function mpfDocumentIsolatesEntriesArray(): void
    {
        $obj = new MpfDocument('0100', 1, [new MpfEntry(0, 1024, 0, 0, 0, false, false, false, null, null)], null);

        self::assertCount(1, $obj->entries);
    }

    #[Test]
    public function mpfAttributesPreservesAdditionalTags(): void
    {
        $obj = new MpfAttributes(
            null,
            [0xB201 => 1],
        );

        self::assertSame([0xB201 => 1], $obj->additionalTags);
    }
}
