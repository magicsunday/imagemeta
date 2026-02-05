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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Parses canonical XMP boolean strings.
     * Ensures strict True/False values are interpreted correctly.
     *
     * @return void
     */
    #[Test]
    public function boolParsesCanonicalValues(): void
    {
        $namespace = 'http://example.com/xmp/';

        $trueDocument = new XmpDocument(
            [
                sprintf('{%s}Flag', $namespace) => 'True',
            ],
            [],
        );

        $falseDocument = new XmpDocument(
            [
                sprintf('{%s}Flag', $namespace) => 'False',
            ],
            [],
        );

        self::assertTrue($trueDocument->bool($namespace, 'Flag'));
        self::assertFalse($falseDocument->bool($namespace, 'Flag'));
    }

    /**
     * Rejects non-canonical boolean representations.
     * It keeps the accessor aligned with the XMP lexical space.
     *
     * @param string $value Boolean candidate to validate.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('nonCanonicalBooleanProvider')]
    public function boolRejectsNonCanonicalValues(string $value): void
    {
        $namespace = 'http://example.com/xmp/';

        $document = new XmpDocument(
            [
                sprintf('{%s}Flag', $namespace) => $value,
            ],
            [],
        );

        self::assertNull($document->bool($namespace, 'Flag'));
    }

    /**
     * Parses a basic rational value into a float.
     * It keeps the numeric parsing rules stable for valid inputs.
     *
     * @return void
     */
    #[Test]
    public function parseNumericValueParsesValidRational(): void
    {
        self::assertSame(0.5, XmpDocument::parseNumericValue('1/2'));
    }

    /**
     * Rejects rational values that use zero-equivalent denominators.
     * It prevents division by zero across multiple textual forms.
     *
     * @param string $denominator Denominator string to test.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('zeroEquivalentDenominatorProvider')]
    public function parseNumericValueRejectsZeroEquivalentDenominators(string $denominator): void
    {
        $value = sprintf('1/%s', $denominator);

        self::assertNull(XmpDocument::parseNumericValue($value));
    }

    /**
     * Rejects malformed rational strings without numeric content.
     * It ensures invalid numerator/denominator pairs return null.
     *
     * @return void
     */
    #[Test]
    public function parseNumericValueRejectsMalformedRational(): void
    {
        self::assertNull(XmpDocument::parseNumericValue('foo/bar'));
    }

    /**
     * Provides zero-equivalent denominators for rational parsing tests.
     *
     * @return array<string, array{string}>
     */
    public static function zeroEquivalentDenominatorProvider(): array
    {
        return [
            'zero'             => ['0'],
            'plus-zero'        => ['+0'],
            'zero-padded'      => ['00'],
            'decimal'          => ['0.0'],
            'negative-decimal' => ['-0.0'],
        ];
    }

    /**
     * Provides non-canonical boolean representations.
     *
     * @return array<string, array{string}>
     */
    public static function nonCanonicalBooleanProvider(): array
    {
        return [
            'lower-true'  => ['true'],
            'lower-false' => ['false'],
            'upper-true'  => ['TRUE'],
            'upper-false' => ['FALSE'],
            'one'         => ['1'],
            'zero'        => ['0'],
        ];
    }
}
