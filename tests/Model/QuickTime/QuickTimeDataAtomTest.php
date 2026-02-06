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
 * Exercises the QuickTimeDataAtom value object for metadata item data atoms.
 * It verifies construction, locale decomposition, and zero-locale handling.
 */
#[CoversClass(QuickTimeDataAtom::class)]
final class QuickTimeDataAtomTest extends TestCase
{
    /**
     * Constructs a data atom and verifies stored properties.
     *
     * @return void
     */
    #[Test]
    public function constructionPreservesProperties(): void
    {
        $atom = new QuickTimeDataAtom(1, 0x00010002, 'hello');

        self::assertSame(1, $atom->typeIndicator);
        self::assertSame(0x00010002, $atom->locale);
        self::assertSame('hello', $atom->value);
    }

    /**
     * Decomposes locale into country and language indicators.
     *
     * @return void
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
     *
     * @return void
     */
    #[Test]
    public function zeroLocaleReturnsZeroIndicators(): void
    {
        $atom = new QuickTimeDataAtom(1, 0, 'value');

        self::assertSame(0, $atom->countryIndicator());
        self::assertSame(0, $atom->languageIndicator());
    }
}
