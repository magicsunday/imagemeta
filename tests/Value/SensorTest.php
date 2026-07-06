<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Value;

use MagicSunday\ImageMeta\Value\CfaPattern;
use MagicSunday\ImageMeta\Value\Enum\CfaPatternColor;
use MagicSunday\ImageMeta\Value\Sensor;
use MagicSunday\ImageMeta\Value\Traits\EnumFromIntStringNullable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Sensor value object for CFA pattern construction.
 *
 * @internal
 */
#[CoversClass(Sensor::class)]
#[UsesClass(CfaPattern::class)]
#[UsesTrait(EnumFromIntStringNullable::class)]
final class SensorTest extends TestCase
{
    /**
     * Builds a CFA pattern from component identifiers and attaches it to a Sensor.
     * This exercises fromComponents() factory logic and color mapping.
     */
    #[Test]
    public function constructsWithCFAPattern(): void
    {
        $cfaPattern = CfaPattern::fromComponents(
            horizontalRepeatPixelUnit: 2,
            verticalRepeatPixelUnit: 2,
            componentIdentifiers: [
                CfaPatternColor::Red->value,
                CfaPatternColor::Green->value,
                CfaPatternColor::Green->value,
                CfaPatternColor::Blue->value,
            ],
        );

        self::assertInstanceOf(CfaPattern::class, $cfaPattern);

        $sensor = new Sensor(
            cfaPattern: $cfaPattern,
        );

        self::assertSame($cfaPattern, $sensor->cfaPattern);
        self::assertSame(CfaPatternColor::Red, $cfaPattern->colors[0]);
    }
}
