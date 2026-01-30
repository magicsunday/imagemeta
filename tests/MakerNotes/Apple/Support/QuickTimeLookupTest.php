<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple\Support;

use MagicSunday\ImageMeta\MakerNotes\Apple\Support\QuickTimeLookup;
use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuickTimeLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
final class QuickTimeLookupTest extends TestCase
{
    /**
     * Verifies that $lookup->string('Primary', 'Secondary') equals 'value'.
     *
     * @return void
     */
    #[Test]
    public function stringReturnsFirstNonEmptyCandidate(): void
    {
        $meta = new QuickTimeMeta([
            'Primary'   => '',
            'Secondary' => '  value  ',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertSame('value', $lookup->string('Primary', 'Secondary'));
    }

    /**
     * Verifies that $lookup->string('Primary', 'Secondary') is null.
     *
     * @return void
     */
    #[Test]
    public function stringReturnsNullWhenCandidatesAreEmpty(): void
    {
        $meta = new QuickTimeMeta([
            'Primary'   => '',
            'Secondary' => '   ',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertNull($lookup->string('Primary', 'Secondary'));
    }

    /**
     * Verifies that $lookup->float('First', 'Second') equals 42.5.
     *
     * @return void
     */
    #[Test]
    public function floatFallsBackToNumericString(): void
    {
        $meta = new QuickTimeMeta([
            'First'  => 'not-a-number',
            'Second' => '42.5',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertSame(42.5, $lookup->float('First', 'Second'));
    }

    /**
     * Verifies that $lookup->int('Missing') is null.
     *
     * @return void
     */
    #[Test]
    public function intReturnsNullWhenMissing(): void
    {
        $lookup = new QuickTimeLookup(null);

        self::assertNull($lookup->int('Missing'));
    }

    /**
     * Verifies that $lookup->bool('Primary') is false.
     *
     * @return void
     */
    #[Test]
    public function boolReturnsFirstResolvableValue(): void
    {
        $meta = new QuickTimeMeta([
            'Primary'   => 'false',
            'Secondary' => true,
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertFalse($lookup->bool('Primary'));
        self::assertTrue($lookup->bool('Unknown', 'Secondary'));
    }
}
