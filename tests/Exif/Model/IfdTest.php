<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Ifd container with tagged entries and optional next-IFD offsets.
 * It verifies that entries are stored by tag and that nextIfdOffset defaults correctly.
 * The tests confirm lookup by tag returns the expected entry instance.
 * This ensures IFD maps are stable for higher-level EXIF parsing logic.
 */
#[CoversClass(Ifd::class)]
#[UsesClass(IfdEntry::class)]
final class IfdTest extends TestCase
{
    /**
     * Creates an IFD with entries and an explicit next-IFD offset.
     * Confirms both the entry map and the offset are stored as provided.
     *
     * @return void
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
     * Creates an IFD without specifying a next-IFD offset.
     * Verifies the offset defaults to null while the entries remain intact.
     *
     * @return void
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
     * Builds an IFD with multiple entries keyed by tag.
     * Ensures get() returns the matching entry for a known tag ID.
     *
     * @return void
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
     * Uses an IFD containing a single entry.
     * Confirms get() returns null when a tag ID is not present.
     *
     * @return void
     */
    #[Test]
    public function getReturnsNullForUnknownTag(): void
    {
        $cameraEntry = new IfdEntry(0x0110, 2, 1, 'Camera Model');
        $ifd         = new Ifd([$cameraEntry->tag => $cameraEntry]);

        self::assertNull($ifd->get(0x010F));
    }
}
