<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\Rights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Rights value object.
 */
#[CoversClass(Rights::class)]
final class RightsTest extends TestCase
{
    #[Test]
    public function constructsWithCopyright(): void
    {
        $rights = new Rights(
            copyright: '© 2024 John Doe',
            usageTerms: null,
            licenseUrl: null,
            creditLine: null,
        );

        self::assertSame('© 2024 John Doe', $rights->copyright);
    }

    #[Test]
    public function constructsWithAllRightsInfo(): void
    {
        $rights = new Rights(
            copyright: '© 2024 Jane Smith',
            usageTerms: 'Creative Commons BY-SA',
            licenseUrl: 'https://creativecommons.org/licenses/by-sa/4.0/',
            creditLine: 'Photo by Jane Smith',
        );

        self::assertSame('© 2024 Jane Smith', $rights->copyright);
        self::assertSame('Creative Commons BY-SA', $rights->usageTerms);
        self::assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $rights->licenseUrl);
        self::assertSame('Photo by Jane Smith', $rights->creditLine);
    }

    #[Test]
    public function allowsNullValues(): void
    {
        $rights = new Rights(
            copyright: null,
            usageTerms: null,
            licenseUrl: null,
            creditLine: null,
        );

        self::assertNull($rights->copyright);
        self::assertNull($rights->usageTerms);
        self::assertNull($rights->licenseUrl);
        self::assertNull($rights->creditLine);
    }
}
