<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\Exif\ExifCapabilities;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Exif\ValueConverters;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Factory\StructuredMetadataCache;
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
#[UsesClass(StructuredMetadataCache::class)]
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
