<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Resolver;

use MagicSunday\ImageMeta\Curate\Resolver\XmpResolver;
use MagicSunday\ImageMeta\Model\Xmp\XmpDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Curate\Resolver\XmpResolver
 */
final class XmpResolverTest extends TestCase
{
    private const string DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

    #[Test]
    public function stringListHandlesCommaSeparatedValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => 'Alpha, Beta , ,Gamma',
        ]);

        $resolver = new XmpResolver($document);

        self::assertSame(['Alpha', 'Beta', 'Gamma'], $resolver->stringList(self::DC_NAMESPACE, 'subject'));
    }

    #[Test]
    public function stringListTrimsArrayValues(): void
    {
        $document = new XmpDocument([
            '{' . self::DC_NAMESPACE . '}subject' => ['First', ' Second ', ''],
        ]);

        $resolver = new XmpResolver($document);

        self::assertSame(['First', 'Second'], $resolver->stringList(self::DC_NAMESPACE, 'subject'));
    }
}
