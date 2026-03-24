<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Riff;

use MagicSunday\ImageMeta\Core\ParseError;
use MagicSunday\ImageMeta\Parse\Riff\RiffParserConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiffParserConfig::class)]
final class RiffParserConfigTest extends TestCase
{
    #[Test]
    public function acceptsValidDefaults(): void
    {
        $config = new RiffParserConfig();

        self::assertSame(100_000, $config->maxChunkCount);
        self::assertSame(16 * 1024 * 1024, $config->maxMetadataPayloadSize);
        self::assertSame(50, $config->maxListDepth);
    }

    #[Test]
    public function rejectsZeroMaxChunkCount(): void
    {
        $this->expectException(ParseError::class);

        new RiffParserConfig(maxChunkCount: 0);
    }

    #[Test]
    public function rejectsNegativeMaxMetadataPayloadSize(): void
    {
        $this->expectException(ParseError::class);

        new RiffParserConfig(maxMetadataPayloadSize: -1);
    }

    #[Test]
    public function rejectsZeroMaxListDepth(): void
    {
        $this->expectException(ParseError::class);

        new RiffParserConfig(maxListDepth: 0);
    }
}
