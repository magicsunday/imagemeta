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
use MagicSunday\ImageMeta\Model\Xmp\XmpStructuredValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Exercises XmpDocument merging and value aggregation behavior.
 * It verifies duplicate properties are combined into arrays across documents.
 * The suite checks namespace prefix maps are merged without losing existing prefixes.
 * This keeps XMP document composition consistent when multiple packets are combined.
 *
 * @internal
 */
#[CoversClass(XmpDocument::class)]
#[CoversClass(XmpStructuredValue::class)]
final class XmpDocumentTest extends TestCase
{
    /**
     * Merges duplicate keys into arrays and combines namespace prefixes.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function mergeAggregatesValuesAndPrefixes(): void
    {
        $first  = new XmpDocument(
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
     */
    #[Test]
    public function mergeWithNoDocumentsReturnsEmptyDocument(): void
    {
        $merged = XmpDocument::merge();

        self::assertSame([], $merged->data);
        self::assertSame([], $merged->namespacePrefixes);
        self::assertSame([], $merged->structuredData);
    }

    /**
     * Merges structured parseType Resource properties under their parent key.
     */
    #[Test]
    public function mergeAggregatesStructuredValues(): void
    {
        $namespace   = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';

        $first       = new XmpDocument(
            [],
            [],
            [
                sprintf('{%s}CreatorContactInfo', $namespace) => new XmpStructuredValue([
                    sprintf('{%s}CiEmailWork', $namespace) => 'jane@example.com',
                ]),
            ],
        );

        $second      = new XmpDocument(
            [],
            [],
            [
                sprintf('{%s}CreatorContactInfo', $namespace) => new XmpStructuredValue([
                    sprintf('{%s}CiTelWork', $namespace) => '+49 30 555',
                ]),
            ],
        );

        $merged      = XmpDocument::merge($first, $second);

        $contactInfo = $merged->structured($namespace, 'CreatorContactInfo');
        self::assertInstanceOf(XmpStructuredValue::class, $contactInfo);
        self::assertSame('jane@example.com', $contactInfo->get($namespace, 'CiEmailWork'));
        self::assertSame('+49 30 555', $contactInfo->get($namespace, 'CiTelWork'));
    }

    /**
     * Returns structured values through get()/find() when keyed by the parent property.
     */
    #[Test]
    public function getReturnsStructuredValues(): void
    {
        $namespace = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';
        $document  = new XmpDocument(
            [],
            [],
            [
                sprintf('{%s}CreatorContactInfo', $namespace) => new XmpStructuredValue([
                    sprintf('{%s}CiUrlWork', $namespace) => 'https://example.com',
                ]),
            ],
        );

        $value     = $document->get($namespace, 'CreatorContactInfo');
        self::assertInstanceOf(XmpStructuredValue::class, $value);
        self::assertSame('https://example.com', $value->get($namespace, 'CiUrlWork'));
        self::assertInstanceOf(XmpStructuredValue::class, $document->find('CreatorContactInfo'));
    }

    /**
     * Returns a single list item for scalar text values.
     * Ensures commas inside text are not treated as separators.
     */
    #[Test]
    public function stringListPreservesScalarTextWithCommas(): void
    {
        $namespace = 'http://example.com/xmp/';
        $document  = new XmpDocument(
            [
                sprintf('{%s}Title', $namespace) => 'ACME, Inc.',
            ],
            [],
        );

        self::assertSame(['ACME, Inc.'], $document->stringList($namespace, 'Title'));
    }

    /**
     * Returns list items when the property is already represented as an array.
     * It keeps the existing trimming behavior for container values.
     */
    #[Test]
    public function stringListReturnsArrayValues(): void
    {
        $namespace = 'http://example.com/xmp/';
        $document  = new XmpDocument(
            [
                sprintf('{%s}Keywords', $namespace) => ['  one ', 'two', ''],
            ],
            [],
        );

        self::assertSame(['one', 'two'], $document->stringList($namespace, 'Keywords'));
    }

    /**
     * Returns an empty list for empty scalar values.
     */
    #[Test]
    public function stringListSkipsEmptyScalar(): void
    {
        $namespace = 'http://example.com/xmp/';
        $document  = new XmpDocument(
            [
                sprintf('{%s}Title', $namespace) => '   ',
            ],
            [],
        );

        self::assertSame([], $document->stringList($namespace, 'Title'));
    }

    /**
     * Returns scalar text with leading/trailing spaces preserved via rawString().
     */
    #[Test]
    public function rawStringPreservesWhitespace(): void
    {
        $namespace = 'http://example.com/xmp/';
        $document  = new XmpDocument([
            sprintf('{%s}Title', $namespace) => '  Hello World  ',
        ]);

        self::assertSame('  Hello World  ', $document->rawString($namespace, 'Title'));
    }

    /**
     * Returns array values with per-item leading/trailing spaces preserved via rawStringList().
     */
    #[Test]
    public function rawStringListPreservesPerItemWhitespace(): void
    {
        $namespace = 'http://example.com/xmp/';
        $document  = new XmpDocument([
            sprintf('{%s}Keywords', $namespace) => ['  one ', ' two ', ''],
        ]);

        self::assertSame(['  one ', ' two ', ''], $document->rawStringList($namespace, 'Keywords'));
    }

    /**
     * Parses canonical XMP boolean strings.
     * Ensures strict True/False values are interpreted correctly.
     */
    #[Test]
    public function boolParsesCanonicalValues(): void
    {
        $namespace     = 'http://example.com/xmp/';

        $trueDocument  = new XmpDocument(
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
     */
    #[Test]
    #[DataProvider('nonCanonicalBooleanProvider')]
    public function boolRejectsNonCanonicalValues(string $value): void
    {
        $namespace = 'http://example.com/xmp/';

        $document  = new XmpDocument(
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
     */
    #[Test]
    public function parseNumericValueRejectsMalformedRational(): void
    {
        self::assertNull(XmpDocument::parseNumericValue('foo/bar'));
    }

    /**
     * Rejects text that contains embedded digits but is not a strict numeric form.
     * Prevents accidental coercion of textual metadata into numeric values.
     */
    #[Test]
    public function parseNumericValueRejectsEmbeddedNumbers(): void
    {
        self::assertNull(XmpDocument::parseNumericValue('abc123def'));
    }

    /**
     * Parses strict numeric forms: integer, negative float, and rational.
     */
    #[Test]
    public function parseNumericValueAcceptsStrictNumericForms(): void
    {
        self::assertSame(42.0, XmpDocument::parseNumericValue('42'));
        self::assertSame(-1.5, XmpDocument::parseNumericValue('-1.5'));
        self::assertSame(1.5, XmpDocument::parseNumericValue('3/2'));
    }

    /**
     * Accepts strict decimal integer forms for XMP Integer type.
     */
    #[Test]
    public function intAcceptsStrictDecimalIntegers(): void
    {
        $ns  = 'http://example.com/xmp/';

        $doc = new XmpDocument([
            sprintf('{%s}A', $ns) => '0',
            sprintf('{%s}B', $ns) => '-12',
            sprintf('{%s}C', $ns) => '+34',
        ]);

        self::assertSame(0, $doc->int($ns, 'A'));
        self::assertSame(-12, $doc->int($ns, 'B'));
        self::assertSame(34, $doc->int($ns, 'C'));
    }

    /**
     * Rejects non-integer forms for XMP Integer accessor.
     *
     * @param string $value Non-integer string to test.
     */
    #[Test]
    #[DataProvider('nonIntegerValueProvider')]
    public function intRejectsNonIntegerForms(string $value): void
    {
        $ns  = 'http://example.com/xmp/';
        $doc = new XmpDocument([sprintf('{%s}Val', $ns) => $value]);

        self::assertNull($doc->int($ns, 'Val'));
    }

    /**
     * Provides non-integer string forms that must be rejected by int().
     *
     * @return array<string, array{string}>
     */
    public static function nonIntegerValueProvider(): array
    {
        return [
            'decimal'    => ['1.0'],
            'scientific' => ['1e3'],
            'rational'   => ['1/2'],
            'embedded'   => ['foo42'],
        ];
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
