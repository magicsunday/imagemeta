<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Mpf;

use MagicSunday\ImageMeta\Model\Mpf\MpfAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the MpfAttributes value object for optional MP Attribute IFD fields.
 * It verifies construction with full data and null edge cases.
 */
#[CoversClass(MpfAttributes::class)]
final class MpfAttributesTest extends TestCase
{
    /**
     * Constructs attributes with all fields populated.
     */
    #[Test]
    public function constructionPreservesProperties(): void
    {
        $panoramaAngle = [['numerator' => 360, 'denominator' => 1]];
        $panoramaAxis  = [['numerator' => 0, 'denominator' => 1]];

        $attributes = new MpfAttributes(
            imageUidList: "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F",
            totalFrames: 3,
            individualImageNumber: 1,
            panoramaAngle: $panoramaAngle,
            panoramaAxis: $panoramaAxis,
            additionalTags: [0xB001 => 'extra'],
        );

        self::assertSame("\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F", $attributes->imageUidList);
        self::assertSame(3, $attributes->totalFrames);
        self::assertSame(1, $attributes->individualImageNumber);
        self::assertSame($panoramaAngle, $attributes->panoramaAngle);
        self::assertSame($panoramaAxis, $attributes->panoramaAxis);
        self::assertSame([0xB001 => 'extra'], $attributes->additionalTags);
    }

    /**
     * Accepts null for all optional properties with empty additional tags.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $attributes = new MpfAttributes(
            imageUidList: null,
            totalFrames: null,
            individualImageNumber: null,
            panoramaAngle: null,
            panoramaAxis: null,
            additionalTags: [],
        );

        self::assertNull($attributes->imageUidList);
        self::assertNull($attributes->totalFrames);
        self::assertNull($attributes->individualImageNumber);
        self::assertNull($attributes->panoramaAngle);
        self::assertNull($attributes->panoramaAxis);
        self::assertSame([], $attributes->additionalTags);
    }
}
