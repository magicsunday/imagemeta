<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests\Model\Xmp;

use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmpDocument::class)]
final class XmpDocumentTest extends TestCase
{
    /**
     * Verifies that $merged->data equals [
     * '{ns1}PropA' => ['ValueA', 'ValueB'],
     * '{ns2}List'  => ['One', 'Two'],
     * '{ns3}PropC' => 'C',
     * ].
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
     * Verifies that $merged->data equals [].
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
