<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpValueAccumulator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises XMP value accumulation to preserve duplicates and order.
 *
 * @internal
 */
#[CoversClass(XmpValueAccumulator::class)]
final class XmpValueAccumulatorTest extends TestCase
{
    /**
     * Keeps duplicate values and order when merging arrays.
     */
    #[Test]
    public function mergePreservesDuplicatesForArrays(): void
    {
        $data = [
            '{ns}List' => ['a', 'b'],
        ];

        XmpValueAccumulator::merge($data, '{ns}List', ['b', 'a']);

        self::assertSame(['a', 'b', 'b', 'a'], $data['{ns}List']);
    }

    /**
     * Keeps fixed-count EXIF array payloads cardinality-stable after merge.
     */
    #[Test]
    public function mergeKeepsFixedCountExifArrayCardinalityStable(): void
    {
        $data = [
            '{exif}LensSpecification' => ['24/1', '70/1', '28/10', '28/10'],
        ];

        XmpValueAccumulator::merge(
            $data,
            '{exif}LensSpecification',
            ['24/1', '70/1', '28/10', '28/10'],
        );

        self::assertSame(
            ['24/1', '70/1', '28/10', '28/10', '24/1', '70/1', '28/10', '28/10'],
            $data['{exif}LensSpecification'],
        );
    }

    /**
     * Appends scalar values even when they duplicate existing entries.
     */
    #[Test]
    public function mergeAppendsScalarToArrayEvenWhenDuplicate(): void
    {
        $data = [
            '{ns}Value' => ['a'],
        ];

        XmpValueAccumulator::merge($data, '{ns}Value', 'a');

        self::assertSame(['a', 'a'], $data['{ns}Value']);
    }

    /**
     * Preserves the existing scalar and then appends array entries.
     */
    #[Test]
    public function mergeAppendsArrayToScalarInOrder(): void
    {
        $data = [
            '{ns}Value' => 'a',
        ];

        XmpValueAccumulator::merge($data, '{ns}Value', ['a', 'b']);

        self::assertSame(['a', 'a', 'b'], $data['{ns}Value']);
    }

    /**
     * Stores both scalar values, even when they are identical.
     */
    #[Test]
    public function mergePreservesDuplicateScalars(): void
    {
        $data = [
            '{ns}Value' => 'a',
        ];

        XmpValueAccumulator::merge($data, '{ns}Value', 'a');

        self::assertSame(['a', 'a'], $data['{ns}Value']);
    }
}
