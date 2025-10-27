<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Xmp;

use MagicSunday\ImageMeta\Curate\Xmp\XmpReader;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Xmp\XmpReader
 */
final class XmpReaderTest extends TestCase
{
    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    #[Test]
    public function stringListHandlesCommaSeparatedValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => 'Alpha, Beta , ,Gamma',
        ]);

        self::assertSame(['Alpha', 'Beta', 'Gamma'], XmpReader::stringList($document, self::DC_NAMESPACE, 'subject'));
    }

    #[Test]
    public function stringListTrimsArrayValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => ['First', ' Second ', ''],
        ]);

        self::assertSame(['First', 'Second'], XmpReader::stringList($document, self::DC_NAMESPACE, 'subject'));
    }
}
