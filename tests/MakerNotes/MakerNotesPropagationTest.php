<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\Core\ExifCapabilities;
use MagicSunday\ImageMeta\MakerNotes\MakerNotesRecord;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Exif\ValueConverters;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\MetadataReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Verifies that maker note metadata propagates through the model layer.
 */
#[CoversClass(MetadataReader::class)]
#[UsesClass(ExifCapabilities::class)]
#[UsesClass(MakerNotesRecord::class)]
#[UsesClass(Ifd::class)]
#[UsesClass(ParsedExif::class)]
#[UsesClass(ValueConverters::class)]
final class MakerNotesPropagationTest extends TestCase
{
    /**
     * Ensures the EXIF document exposes the provided maker notes metadata.
     */
    #[Test]
    public function exifDocumentReturnsMakerNotes(): void
    {
        $makerNotes = new MakerNotesRecord('Acme', 4, str_repeat('0', 40));
        $document   = new ParsedExif(new Ifd([]), null, null, null, null, $makerNotes);

        self::assertSame($makerNotes, $document->makerNotes());
    }

    /**
     * Ensures the aggregate metadata object stores the optional maker notes metadata.
     */
    #[Test]
    public function metadataAggregateCarriesMakerNotes(): void
    {
        $makerNotes = new MakerNotesRecord('Acme', 4, str_repeat('0', 40));
        $metadata   = new Metadata([], null, null, [], null, $makerNotes);

        self::assertSame($makerNotes, $metadata->makerNotes);
    }
}
