<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ExifCapabilities;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\ImageMeta\Core\ExifCapabilities
 */
final class ExifCapabilitiesTest extends TestCase
{
    #[Test]
    public function mapsExifVersionToCapabilityProfile(): void
    {
        self::assertSame('2.0', ExifCapabilities::fromVersion('0200'));
        self::assertSame('2.0', ExifCapabilities::fromVersion('2.00'));
    }
}
