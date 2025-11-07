<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\Microsoft;

use MagicSunday\ImageMeta\Model\Microsoft\MicrosoftXpTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Microsoft Windows XP proprietary tag constants.
 */
#[CoversClass(MicrosoftXpTag::class)]
final class MicrosoftXpTagTest extends TestCase
{
    /**
     * Verifies that all Microsoft XP tags are defined with correct values.
     */
    #[Test]
    public function microsoftXpTagsAreDefined(): void
    {
        self::assertSame(0x9C9B, MicrosoftXpTag::XP_TITLE);
        self::assertSame(0x9C9C, MicrosoftXpTag::XP_COMMENT);
        self::assertSame(0x9C9D, MicrosoftXpTag::XP_AUTHOR);
        self::assertSame(0x9C9E, MicrosoftXpTag::XP_KEYWORDS);
        self::assertSame(0x9C9F, MicrosoftXpTag::XP_SUBJECT);
    }

    /**
     * Verifies that XP tag values are in sequence.
     */
    #[Test]
    public function xpTagsAreSequential(): void
    {
        self::assertSame(MicrosoftXpTag::XP_TITLE + 1, MicrosoftXpTag::XP_COMMENT);
        self::assertSame(MicrosoftXpTag::XP_COMMENT + 1, MicrosoftXpTag::XP_AUTHOR);
        self::assertSame(MicrosoftXpTag::XP_AUTHOR + 1, MicrosoftXpTag::XP_KEYWORDS);
        self::assertSame(MicrosoftXpTag::XP_KEYWORDS + 1, MicrosoftXpTag::XP_SUBJECT);
    }
}
