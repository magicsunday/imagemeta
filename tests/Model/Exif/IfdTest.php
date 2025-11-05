<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\Ifd;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see Ifd} model.
 */
#[CoversClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
final class IfdTest extends TestCase
{
    /**
     * Ensures the constructor exposes the provided entries and optional next IFD offset.
     */
    #[Test]
    public function constructorStoresEntriesAndNextOffset(): void
    {
        $entry = new IfdEntry(0x010F, 2, 1, 'MagicSunday');
        $ifd   = new Ifd([$entry->tag => $entry], 256);

        self::assertSame([$entry->tag => $entry], $ifd->entries);
        self::assertSame(256, $ifd->nextIfdOffset);
    }

    /**
     * Verifies that the next IFD offset defaults to null when it is omitted.
     */
    #[Test]
    public function constructorDefaultsNextOffsetToNull(): void
    {
        $entry = new IfdEntry(0x0110, 2, 1, 'Camera Model');
        $ifd   = new Ifd([$entry->tag => $entry]);

        self::assertSame([$entry->tag => $entry], $ifd->entries);
        self::assertNull($ifd->nextIfdOffset);
    }

    /**
     * Ensures that get() returns the entry associated with a known tag identifier.
     */
    #[Test]
    public function getReturnsEntryForKnownTag(): void
    {
        $cameraEntry = new IfdEntry(0x0110, 2, 1, 'Camera Model');
        $artistEntry = new IfdEntry(0x013B, 2, 1, 'MagicSunday');

        $ifd = new Ifd([
            $cameraEntry->tag => $cameraEntry,
            $artistEntry->tag => $artistEntry,
        ]);

        self::assertSame($artistEntry, $ifd->get(0x013B));
    }

    /**
     * Ensures that get() returns null when the tag identifier is not present in the directory.
     */
    #[Test]
    public function getReturnsNullForUnknownTag(): void
    {
        $cameraEntry = new IfdEntry(0x0110, 2, 1, 'Camera Model');
        $ifd         = new Ifd([$cameraEntry->tag => $cameraEntry]);

        self::assertNull($ifd->get(0x010F));
    }
}
