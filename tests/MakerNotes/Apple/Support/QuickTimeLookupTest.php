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
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises QuickTimeLookup helper methods for typed and fallback access.
 * It verifies string, int, and float lookups trim and coerce values as needed.
 * The suite checks fallback ordering when multiple keys are provided.
 * This keeps QuickTime metadata access predictable for maker note merging.
 *
 * @internal
 */
#[CoversClass(QuickTimeLookup::class)]
#[UsesClass(QuickTimeMeta::class)]
final class QuickTimeLookupTest extends TestCase
{
    /**
     * Provides a primary empty string and a secondary string with surrounding whitespace.
     * Ensures string() trims values and returns the first non-empty candidate.
     */
    #[Test]
    public function stringReturnsFirstNonEmptyCandidate(): void
    {
        $meta   = new QuickTimeMeta([
            'Primary'   => '',
            'Secondary' => '  value  ',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertSame('value', $lookup->string('Primary', 'Secondary'));
    }

    /**
     * Uses only empty or whitespace-only string candidates.
     * Confirms string() returns null when no usable value is found.
     */
    #[Test]
    public function stringReturnsNullWhenCandidatesAreEmpty(): void
    {
        $meta   = new QuickTimeMeta([
            'Primary'   => '',
            'Secondary' => '   ',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertNull($lookup->string('Primary', 'Secondary'));
    }

    /**
     * Supplies a non-numeric first value and a numeric string fallback.
     * Ensures float() skips invalid candidates and parses a numeric string.
     */
    #[Test]
    public function floatFallsBackToNumericString(): void
    {
        $meta   = new QuickTimeMeta([
            'First'  => 'not-a-number',
            'Second' => '42.5',
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertSame(42.5, $lookup->float('First', 'Second'));
    }

    /**
     * Uses a QuickTimeLookup with no metadata attached.
     * Verifies int() returns null when the key cannot be resolved.
     */
    #[Test]
    public function intReturnsNullWhenMissing(): void
    {
        $lookup = new QuickTimeLookup(null);

        self::assertNull($lookup->int('Missing'));
    }

    /**
     * Uses a string "false" primary and a boolean true secondary value.
     * Ensures bool() resolves the first candidate and can fall back to a secondary key.
     */
    #[Test]
    public function boolReturnsFirstResolvableValue(): void
    {
        $meta   = new QuickTimeMeta([
            'Primary'   => 'false',
            'Secondary' => true,
        ]);

        $lookup = new QuickTimeLookup($meta);

        self::assertFalse($lookup->bool('Primary'));
        self::assertTrue($lookup->bool('Unknown', 'Secondary'));
    }
}
