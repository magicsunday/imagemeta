<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises XmpDocument merging and value aggregation behavior.
 * It verifies duplicate properties are combined into arrays across documents.
 * The suite checks namespace prefix maps are merged without losing existing prefixes.
 * This keeps XMP document composition consistent when multiple packets are combined.
 *
 * @internal
 */
#[CoversClass(XmpDocument::class)]
final class XmpDocumentTest extends TestCase
{
    /**
     * Merges duplicate keys into arrays and combines namespace prefixes.
     * It exercises the scenario described by the test name.
     *
     * @return void
     */
    #[Test]
    public function mergeAggregatesValuesAndPrefixes(): void
    {
        $first = new XmpDocument(
            [
                '{ns1}PropA' => 'ValueA',
                '{ns2}List'  => ['One'],
            ],
            [
                'ns1' => 'p1',
            ],
        );

        $second = new XmpDocument(
            [
                '{ns1}PropA' => 'ValueB',
                '{ns2}List'  => ['Two'],
                '{ns3}PropC' => 'C',
            ],
            [
                'ns2' => 'p2',
                'ns3' => 'p3',
            ],
        );

        $merged = XmpDocument::merge($first, $second);

        self::assertSame(
            [
                '{ns1}PropA' => ['ValueA', 'ValueB'],
                '{ns2}List'  => ['One', 'Two'],
                '{ns3}PropC' => 'C',
            ],
            $merged->data,
        );

        self::assertSame(
            [
                'ns1' => 'p1',
                'ns2' => 'p2',
                'ns3' => 'p3',
            ],
            $merged->namespacePrefixes,
        );
    }

    /**
     * Returns an empty document when merging no inputs.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
     */
    #[Test]
    public function mergeWithNoDocumentsReturnsEmptyDocument(): void
    {
        $merged = XmpDocument::merge();

        self::assertSame([], $merged->data);
        self::assertSame([], $merged->namespacePrefixes);
    }
}
