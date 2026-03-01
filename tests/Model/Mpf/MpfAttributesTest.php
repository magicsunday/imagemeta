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
        $attributes = new MpfAttributes(
            individualImageNumber: 1,
            additionalTags: [0xB001 => 'extra'],
        );

        self::assertSame(1, $attributes->individualImageNumber);
        self::assertSame([0xB001 => 'extra'], $attributes->additionalTags);
    }

    /**
     * Accepts null for all optional properties with empty additional tags.
     */
    #[Test]
    public function allowsNullValues(): void
    {
        $attributes = new MpfAttributes(
            individualImageNumber: null,
            additionalTags: [],
        );

        self::assertNull($attributes->individualImageNumber);
        self::assertSame([], $attributes->additionalTags);
    }
}
