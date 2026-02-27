<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeMetadataDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function array_map;

/**
 * @internal
 */
#[CoversClass(QuickTimeMetadataDecoder::class)]
final class QuickTimeMetadataDecoderTest extends TestCase
{
    #[Test]
    public function usesDedicatedFullAtomHeaderValidator(): void
    {
        $reflection = new ReflectionClass(QuickTimeMetadataDecoder::class);
        $methods    = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PRIVATE),
        );

        self::assertContains('validateFullAtomHeader', $methods);
    }
}
