<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\QuickTime;

use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the QuickTimeDataAtom value object for locale decomposition logic.
 */
#[CoversClass(QuickTimeDataAtom::class)]
final class QuickTimeDataAtomTest extends TestCase
{
    /**
     * Decomposes locale into country and language indicators.
     */
    #[Test]
    public function localeDecomposition(): void
    {
        // Country = 0x1234, Language = 0x5678
        $atom = new QuickTimeDataAtom(1, 0x12345678, 'test');

        self::assertSame(0x1234, $atom->countryIndicator());
        self::assertSame(0x5678, $atom->languageIndicator());
    }

    /**
     * Returns zero for both indicators when locale is zero.
     */
    #[Test]
    public function zeroLocaleReturnsZeroIndicators(): void
    {
        $atom = new QuickTimeDataAtom(1, 0, 'value');

        self::assertSame(0, $atom->countryIndicator());
        self::assertSame(0, $atom->languageIndicator());
    }
}
