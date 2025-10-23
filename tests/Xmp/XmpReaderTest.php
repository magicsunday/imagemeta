<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Xmp;

use MagicSunday\ImageMeta\Parse\Xmp\XmpReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates the XMP reader behaviour.
 *
 * @covers \MagicSunday\ImageMeta\Parse\Xmp\XmpReader
 */
final class XmpReaderTest extends TestCase
{
    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    /**
     * Ensures mixed text and CDATA nodes are concatenated verbatim.
     */
    #[Test]
    public function testMixedContentIsPreserved(): void
    {
        $xml = '<dc:title xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . 'Prefix <![CDATA[<tag> & middle]]> suffix'
            . '</dc:title>';

        $reader   = new XmpReader();
        $document = $reader->parse($xml);

        $key = '{' . self::DC_NAMESPACE . '}title';
        self::assertArrayHasKey($key, $document->data);
        self::assertSame('Prefix <tag> & middle suffix', $document->data[$key]);
        self::assertSame('Prefix <tag> & middle suffix', $document->get(self::DC_NAMESPACE, 'title'));
        self::assertSame('Prefix <tag> & middle suffix', $document->find('title'));
    }
}
