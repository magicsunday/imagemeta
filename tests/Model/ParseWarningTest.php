<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model;

use MagicSunday\ImageMeta\Core\BoundsError;
use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Model\ParseWarning;
use MagicSunday\ImageMeta\Value\Enum\ParseWarningScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ParseWarning value object that reports tolerated damage to consumers.
 *
 * @internal
 */
#[CoversClass(ParseWarning::class)]
#[CoversClass(ParseWarningScope::class)]
final class ParseWarningTest extends TestCase
{
    /**
     * A tolerated ParseError keeps its numeric code so consumers can branch on it.
     */
    #[Test]
    public function fromThrowableKeepsParseErrorCodeAndMessage(): void
    {
        $warning = ParseWarning::fromThrowable(
            ParseWarningScope::Container,
            new ParseError('box xyz exceeds container bounds', 1262),
        );

        self::assertSame(ParseWarningScope::Container, $warning->scope);
        self::assertSame(1262, $warning->code);
        self::assertSame('box xyz exceeds container bounds', $warning->message);
    }

    /**
     * A tolerated BoundsError (truncated data) is reported with its message.
     */
    #[Test]
    public function fromThrowableAcceptsBoundsError(): void
    {
        $warning = ParseWarning::fromThrowable(ParseWarningScope::Exif, new BoundsError('read past end'));

        self::assertSame(ParseWarningScope::Exif, $warning->scope);
        self::assertSame(0, $warning->code);
        self::assertSame('read past end', $warning->message);
    }

    /**
     * Scopes serialise to stable string values for logging and JSON consumers.
     */
    #[Test]
    public function scopeValuesAreStable(): void
    {
        self::assertSame('container', ParseWarningScope::Container->value);
        self::assertSame('exif', ParseWarningScope::Exif->value);
        self::assertSame('xmp', ParseWarningScope::Xmp->value);
        self::assertSame('iptc', ParseWarningScope::Iptc->value);
    }
}
