<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Exercises IfdEntry construction for scalar and list-based values.
 * It verifies tag, type, count, and value fields are stored exactly as provided.
 * The suite covers numeric lists to ensure object instances are preserved.
 * This keeps IFD entries reliable as low-level EXIF building blocks.
 */
#[CoversClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class IfdEntryTest extends TestCase
{
    /**
     * Creates an IFD entry with scalar tag metadata and a string value.
     * Verifies the tag, type, count, and value are stored exactly as supplied.
     */
    #[Test]
    public function constructorAssignsScalarValues(): void
    {
        $entry = new IfdEntry(0x010F, 2, 1, 'MagicSunday');

        self::assertSame(0x010F, $entry->tag);
        self::assertSame(2, $entry->type);
        self::assertSame(1, $entry->count);
        self::assertSame('MagicSunday', $entry->value);
    }

    /**
     * Uses an ExifNumericList as the entry value with a matching element count.
     * Confirms the entry preserves the object instance and metadata fields.
     */
    #[Test]
    public function constructorPreservesArrayValues(): void
    {
        $value = new ExifNumericList([1, 2, 3]);

        $entry = new IfdEntry(0x8769, 3, count($value->values), $value);

        self::assertSame(0x8769, $entry->tag);
        self::assertSame(3, $entry->type);
        self::assertSame(3, $entry->count);
        self::assertSame($value, $entry->value);
    }
}
