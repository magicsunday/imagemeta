<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Text;

use MagicSunday\ImageMeta\Exif\Text\UndefinedTextMarker;
use MagicSunday\ImageMeta\Value\Enum\CharacterEncoding;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the UndefinedTextMarker enum for canonical marker resolution and encoding mapping.
 */
final class UndefinedTextMarkerTest extends TestCase
{
    #[Test]
    public function markerContainerIsImplementedAsStringBackedEnum(): void
    {
        self::assertTrue(enum_exists(UndefinedTextMarker::class));
    }

    #[Test]
    public function canonicalMarkerResolutionStillSupportsAsciiAndUnknownPrefixes(): void
    {
        self::assertSame('ASCII', UndefinedTextMarker::canonicalMarkerFromPrefix("ASCII\0\0\0"));
        self::assertSame('', UndefinedTextMarker::canonicalMarkerFromPrefix("???\0\0\0\0\0"));
    }

    #[Test]
    public function encodingResolutionStillMapsCanonicalMarkers(): void
    {
        self::assertSame(CharacterEncoding::Ascii, UndefinedTextMarker::encodingForMarker('ASCII'));
        self::assertSame(CharacterEncoding::Undefined, UndefinedTextMarker::encodingForMarker('UNDEFINED'));
        self::assertNull(UndefinedTextMarker::encodingForMarker('UNKNOWN'));
    }
}
