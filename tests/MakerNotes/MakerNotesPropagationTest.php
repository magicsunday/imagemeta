<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesMetadata;
use MagicSunday\ImageMeta\Model\Exif\ExifDocument;
use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Metadata;
use function str_repeat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that maker note metadata propagates through the model layer.
 */
final class MakerNotesPropagationTest extends TestCase
{
    /**
     * Ensures the EXIF document exposes the provided maker notes metadata.
     */
    #[Test]
    public function exifDocumentReturnsMakerNotes(): void
    {
        $makerNotes = new MakerNotesMetadata('Acme', 4, str_repeat('0', 40));
        $document   = new ExifDocument(new Ifd([]), null, null, null, null, $makerNotes);

        self::assertSame($makerNotes, $document->makerNotes());
    }

    /**
     * Ensures the aggregate metadata object stores the optional maker notes metadata.
     */
    #[Test]
    public function metadataAggregateCarriesMakerNotes(): void
    {
        $makerNotes = new MakerNotesMetadata('Acme', 4, str_repeat('0', 40));
        $metadata   = new Metadata([], null, null, [], null, $makerNotes);

        self::assertSame($makerNotes, $metadata->makerNotes);
    }
}
