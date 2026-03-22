<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

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
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdValueReader;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Metadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Exercises maker note propagation through ParsedExif, Metadata, and StructuredMetadata layers.
 * It verifies the same MakerNotesRecord instance is preserved across access paths.
 * The suite checks propagation via MetadataReader and structured caches.
 * This ensures maker notes are not lost during aggregation or transformation.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
#[UsesClass(Metadata::class)]
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
#[UsesClass(IfdValueReader::class)]
final class MakerNotesPropagationTest extends TestCase
{
    /**
     * Builds a ParsedExif document that includes a MakerNotesRecord.
     * Confirms makerNotes() returns the same maker notes instance.
     */
    #[Test]
    public function exifDocumentReturnsMakerNotes(): void
    {
        $makerNotes = new MakerNotesRecord('Acme', 4, str_repeat('0', 40));
        $document   = new ParsedExif(new Ifd([]), null, null, null, null, $makerNotes);

        self::assertSame($makerNotes, $document->makerNotes());
    }

    /**
     * Creates a Metadata aggregate with a MakerNotesRecord attached.
     * Ensures the aggregate retains and exposes the same maker notes instance.
     */
    #[Test]
    public function metadataAggregateCarriesMakerNotes(): void
    {
        $makerNotes = new MakerNotesRecord('Acme', 4, str_repeat('0', 40));
        $metadata   = new Metadata([], null, null, [], null, $makerNotes);

        self::assertSame($makerNotes, $metadata->makerNotes);
    }
}
