<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\GpsCoordinate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises GpsCoordinate normalization for hemisphere references and signed values.
 * It verifies raw values are preserved when references are missing.
 * The suite checks formatted string output and signed conversions for N/S/E/W.
 * This keeps GPS coordinate presentation consistent for downstream consumers.
 *
 * @internal
 */
#[CoversClass(GpsCoordinate::class)]
final class GpsCoordinateTest extends TestCase
{
    /**
     * Uses raw GPS values when no hemisphere reference is supplied.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function keepsRawValueWhenReferenceMissing(): void
    {
        $coordinate = new GpsCoordinate(15.75, null, true);

        self::assertSame(15.75, $coordinate->value);
        self::assertSame(15.75, $coordinate->signed);
        self::assertSame('15.75°', (string) $coordinate);
    }

    /**
     * Normalizes hemisphere references and formats coordinate strings.
     * It validates the transformation using representative inputs.
     */
    #[Test]
    public function normalizesReferenceAndFormats(): void
    {
        $coordinate = new GpsCoordinate(42.0, 'west', false);

        self::assertSame(42.0, $coordinate->value);
        self::assertSame('W', $coordinate->reference);
        self::assertSame(-42.0, $coordinate->signed);
        self::assertSame('42° W', (string) $coordinate);
        self::assertFalse($coordinate->isLatitude);
    }
}
