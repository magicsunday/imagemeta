<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Curate\Exif;

use MagicSunday\ImageMeta\Curate\Exif\ValueFactory;
use MagicSunday\ImageMeta\Curate\ExifAssembler;
use MagicSunday\ImageMeta\Curate\StructuredMetadata;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Parse\Xmp\XmpParser;
use MagicSunday\ImageMeta\Value\DepthMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueFactory::class)]
#[UsesClass(ExifAssembler::class)]
#[UsesClass(StructuredMetadata::class)]
#[UsesClass(DepthMap::class)]
final class ValueFactoryTest extends TestCase
{
    #[Test]
    public function assemblesDepthMapFromXmpPacket(): void
    {
        $xml = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns:GDepth="http://ns.google.com/photos/1.0/depthmap/">
  <rdf:Description
    GDepth:Data="ZGVwdGg="
    GDepth:Mime="image/png"
    GDepth:Near="0.25"
    GDepth:Far="10.5"
  />
</rdf:RDF>
XML;

        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            xmpDoc: (new XmpParser())->parse($xml),
        );

        $structured = (new ExifAssembler())->assemble($metadata);

        self::assertInstanceOf(DepthMap::class, $structured->depthMap);
        self::assertSame('ZGVwdGg=', $structured->depthMap->data);
        self::assertSame('image/png', $structured->depthMap->mime);
        self::assertSame(0.25, $structured->depthMap->near);
        self::assertSame(10.5, $structured->depthMap->far);
    }
}
