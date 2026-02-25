<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Parse\Jpeg\IccProfileAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Tests IccProfileAssembler's maxIccProfileSize enforcement.
 *
 * ICC.1:2022 §B.4 — JPEG APP2 embedding mechanism for ICC profiles.
 *
 * @internal
 */
#[CoversClass(IccProfileAssembler::class)]
final class IccProfileAssemblerTest extends TestCase
{
    private const string ICC_SIGNATURE = "ICC_PROFILE\0";

    /**
     * Assembled ICC profile within the configured limit is returned successfully.
     */
    #[Test]
    public function assemblesProfileWithinMaxSize(): void
    {
        $assembler = new IccProfileAssembler(maxIccProfileSize: 1024);

        $data    = str_repeat('A', 100);
        $payload = self::ICC_SIGNATURE . "\x01\x01" . $data;

        $assembler->handleSegment($payload, 0);
        $assembler->finalise();

        self::assertSame($data, $assembler->getProfile());
    }

    /**
     * Assembled ICC profile exceeding the configured limit throws ParseError.
     */
    #[Test]
    public function throwsWhenAssembledProfileExceedsMaxSize(): void
    {
        $assembler = new IccProfileAssembler(maxIccProfileSize: 50);

        $data    = str_repeat('B', 100);
        $payload = self::ICC_SIGNATURE . "\x01\x01" . $data;

        $assembler->handleSegment($payload, 0);

        $this->expectException(ParseError::class);
        $assembler->finalise();
    }
}
