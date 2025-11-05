<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Detect;

use MagicSunday\ImageMeta\Detect\ContainerType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ContainerType enum.
 */
#[CoversClass(ContainerType::class)]
final class ContainerTypeTest extends TestCase
{
    #[Test]
    public function enumCasesAreDifferent(): void
    {
        self::assertNotSame(ContainerType::JPEG, ContainerType::ISOBMFF);
    }
}
