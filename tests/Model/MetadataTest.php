<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests\Model;

use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the aggregated metadata container model.
 *
 * @covers \MagicSunday\ImageMeta\Model\Metadata
 */
#[CoversClass(Metadata::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ExifTag::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
#[UsesClass(QuickTimeMeta::class)]
#[UsesClass(XmpDocument::class)]
#[UsesClass(XmpParser::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(ValueConverters::class)]
final class MetadataTest extends TestCase
{
    /**
     * Ensures that every provided metadata component is exposed unchanged.
     */
    #[Test]
    public function storesProvidedMetadataComponents(): void
    {
        $exifBlobs = [
            'primary-exif-blob',
            'alternate-exif-blob',
        ];

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.content.identifier' => 'movie-123',
        ]);

        $ifd0 = new Ifd([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 2, 1, 'Canon'),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 2, 1, 'EOS R5'),
        ]);
        $exifDoc  = new ParsedExif($ifd0, null, null, null, null);
        $xmpBlobs = [
            '<x:xmpmeta>\n  <!-- primary -->\n</x:xmpmeta>',
            '<x:xmpmeta>\n  <!-- secondary -->\n</x:xmpmeta>',
        ];
        $xmpDoc = new XmpDocument([
            '{http://ns.adobe.com/photoshop/1.0/}DateCreated' => '2024-05-01',
        ]);

        $iccProfile  = 'icc-profile';
        $iccSegments = ['seg-1', 'seg-2'];
        $sampling    = [
            1 => ['horizontal' => 2, 'vertical' => 2],
            2 => ['horizontal' => 1, 'vertical' => 1],
        ];

        $metadata = new Metadata(
            exifBlobs: $exifBlobs,
            quickTime: $quickTime,
            exifDoc: $exifDoc,
            xmpBlobs: $xmpBlobs,
            xmpDoc: $xmpDoc,
            makerNotes: null,
            iccProfile: $iccProfile,
            iccSegments: $iccSegments,
            flashPixStreams: [],
            mpfDocument: null,
            jpegBitsPerSample: 12,
            jpegFrameSamplingFactors: $sampling,
            jpegYCbCrSubSampling: [2, 1],
            mimeType: 'image/heic',
            fileSize: 987_654,
            extension: 'heic',
            digestSha1: 'abc123',
            digestMd5: 'def456',
            jpegFrameWidth: 4096,
            jpegFrameHeight: 2730,
        );

        self::assertSame($exifBlobs, $metadata->exifBlobs);
        self::assertSame($quickTime, $metadata->quickTime);
        self::assertSame($exifDoc, $metadata->exifDoc);
        self::assertSame($xmpBlobs, $metadata->xmpBlobs);
        self::assertSame($xmpDoc, $metadata->xmpDoc);
        self::assertSame($iccProfile, $metadata->iccProfile);
        self::assertSame($iccSegments, $metadata->iccSegments);
        self::assertSame(12, $metadata->jpegBitsPerSample);
        self::assertSame($sampling, $metadata->jpegFrameSamplingFactors);
        self::assertSame([2, 1], $metadata->jpegYCbCrSubSampling);
        self::assertSame('image/heic', $metadata->mimeType);
        self::assertSame(987_654, $metadata->fileSize);
        self::assertSame('heic', $metadata->extension);
        self::assertSame('abc123', $metadata->digestSha1);
        self::assertSame('def456', $metadata->digestMd5);
        self::assertSame(4096, $metadata->jpegFrameWidth);
        self::assertSame(2730, $metadata->jpegFrameHeight);
    }

    /**
     * Ensures the optional metadata components default to null or empty values.
     */
    #[Test]
    public function appliesNullAndEmptyDefaults(): void
    {
        $metadata = new Metadata([], null);

        self::assertSame([], $metadata->exifBlobs);
        self::assertNull($metadata->quickTime);
        self::assertNull($metadata->exifDoc);
        self::assertSame([], $metadata->xmpBlobs);
        self::assertNull($metadata->xmpDoc);
        self::assertNull($metadata->jpegBitsPerSample);
        self::assertNull($metadata->jpegFrameSamplingFactors);
        self::assertNull($metadata->jpegYCbCrSubSampling);
        self::assertNull($metadata->jpegFrameWidth);
        self::assertNull($metadata->jpegFrameHeight);
        self::assertNull($metadata->mimeType);
        self::assertNull($metadata->fileSize);
        self::assertNull($metadata->extension);
        self::assertNull($metadata->digestSha1);
        self::assertNull($metadata->digestMd5);
    }

    /**
     * Demonstrates a typical consumer flow where typed metadata is accessed via the aggregates.
     */
    #[Test]
    public function allowsConsumingAggregatedMetadata(): void
    {
        $exifBlobs = [
            'primary-exif-blob',
            'alternate-exif-blob',
        ];

        $quickTime = new QuickTimeMeta([
            'com.apple.quicktime.content.identifier' => 'clip-42',
        ]);

        $ifd0 = new Ifd([
            ExifTag::MAKE  => new IfdEntry(ExifTag::MAKE, 2, 1, 'Fujifilm'),
            ExifTag::MODEL => new IfdEntry(ExifTag::MODEL, 2, 1, 'X-T5'),
        ]);

        $exifDoc = new ParsedExif($ifd0, null, null, null, null);

        $xmpBlobs = [
            '<x:xmpmeta>\n  <photoshop:DateCreated>2024-06-01</photoshop:DateCreated>\n</x:xmpmeta>',
        ];

        $xmpDoc = new XmpDocument([
            '{http://ns.adobe.com/photoshop/1.0/}DateCreated' => '2024-06-01',
        ]);

        $metadata = new Metadata($exifBlobs, $quickTime, $exifDoc, $xmpBlobs, $xmpDoc);

        self::assertSame('primary-exif-blob', $metadata->exifBlobs[0]);
        self::assertSame('clip-42', $metadata->quickTime?->contentIdentifier());
        self::assertNotNull($metadata->exifDoc);
        self::assertSame('Fujifilm', $metadata->exifDoc->cameraMake());
        self::assertSame('X-T5', $metadata->exifDoc->cameraModel());
        self::assertSame(
            '2024-06-01',
            $metadata->xmpDoc?->get('http://ns.adobe.com/photoshop/1.0/', 'DateCreated')
        );
    }

    /**
     * Ensures consumers can obtain a selectively parsed XMP document without pre-populating it.
     */
    #[Test]
    public function exposesSelectiveXmpDocumentWhenUnavailable(): void
    {
        $xmp = <<<XML
<x:xmpmeta xmlns:x="adobe:ns:meta/"
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:xmp="http://ns.adobe.com/xap/1.0/"
    xmlns:dc="http://purl.org/dc/elements/1.1/">
  <rdf:RDF>
    <rdf:Description>
      <xmp:ModifyDate>2024-04-02T01:02:03Z</xmp:ModifyDate>
      <dc:subject>
        <rdf:Bag>
          <rdf:li>One</rdf:li>
          <rdf:li>Two</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML;

        $metadata = new Metadata([], null, null, [$xmp]);

        $document = $metadata->selectiveXmpDocument();

        self::assertInstanceOf(XmpDocument::class, $document);
        self::assertSame('2024-04-02T01:02:03Z', $document->get('http://ns.adobe.com/xap/1.0/', 'ModifyDate'));
        self::assertSame(['One', 'Two'], $document->find('subject'));
    }

    /**
     * Ensures the already supplied XMP document is reused without invoking the parser again.
     */
    #[Test]
    public function reusesExistingXmpDocument(): void
    {
        $xmpDoc = (new XmpParser())->parse('<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"></rdf:RDF>');

        $metadata = new Metadata([], null, null, [], $xmpDoc);

        self::assertSame($xmpDoc, $metadata->selectiveXmpDocument());
    }
}
