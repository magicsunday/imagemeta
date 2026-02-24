<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Core\PayloadGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the PayloadGuard utility for minimum-length payload checks.
 */
#[CoversClass(PayloadGuard::class)]
#[UsesClass(ParseError::class)]
final class PayloadGuardTest extends TestCase
{
    /**
     * Does not throw when payload meets the minimum length requirement.
     */
    #[Test]
    public function passesWhenPayloadMeetsMinimumLength(): void
    {
        $this->expectNotToPerformAssertions();

        PayloadGuard::ensureMinimumLength("\x00\x01\x02\x03", 4, 'test context', 9999);
    }

    /**
     * Does not throw when payload exceeds the minimum length.
     */
    #[Test]
    public function passesWhenPayloadExceedsMinimumLength(): void
    {
        $this->expectNotToPerformAssertions();

        PayloadGuard::ensureMinimumLength("\x00\x01\x02\x03\x04", 4, 'test context', 9999);
    }

    /**
     * Throws ParseError when payload is shorter than the minimum length.
     */
    #[Test]
    public function throwsWhenPayloadTooShort(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(1234);

        PayloadGuard::ensureMinimumLength("\x00\x01", 4, 'TIFF header', 1234);
    }

    /**
     * Throws ParseError when payload is empty.
     */
    #[Test]
    public function throwsWhenPayloadEmpty(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionCode(5678);

        PayloadGuard::ensureMinimumLength('', 1, 'empty payload', 5678);
    }
}
