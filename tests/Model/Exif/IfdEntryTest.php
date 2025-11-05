<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Exif;

use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\IfdEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see IfdEntry} model.
 */
#[CoversClass(IfdEntry::class)]
#[UsesClass(ExifNumericList::class)]
final class IfdEntryTest extends TestCase
{
    /**
     * Ensures the constructor assigns the provided scalar values to the exposed properties.
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
     * Verifies that complex values such as arrays are exposed unchanged.
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
