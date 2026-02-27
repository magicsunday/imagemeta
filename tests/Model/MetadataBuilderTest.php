<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model;

use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
use MagicSunday\ImageMeta\Factory\StructuredMetadataCache;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Model\MetadataBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Verifies MetadataBuilder wiring for structured metadata assembly dependencies.
 */
#[CoversClass(MetadataBuilder::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(StructuredMetadataBuilder::class)]
#[UsesClass(StructuredMetadataCache::class)]
final class MetadataBuilderTest extends TestCase
{
    #[Test]
    public function reusesStructuredMetadataBuilderAcrossBuildCalls(): void
    {
        $builder = new MetadataBuilder();
        $first   = $builder->withFileIdentity(extension: 'jpg')->build();
        $second  = $builder->withFileIdentity(extension: 'heic')->build();

        $cacheProperty = new ReflectionProperty(Metadata::class, 'structuredCache');
        $firstCache    = $cacheProperty->getValue($first);
        $secondCache   = $cacheProperty->getValue($second);

        self::assertInstanceOf(StructuredMetadataCache::class, $firstCache);
        self::assertInstanceOf(StructuredMetadataCache::class, $secondCache);
        self::assertNotSame($firstCache, $secondCache);

        $builderProperty         = new ReflectionProperty(StructuredMetadataCache::class, 'builder');
        $firstStructuredBuilder  = $builderProperty->getValue($firstCache);
        $secondStructuredBuilder = $builderProperty->getValue($secondCache);

        self::assertInstanceOf(StructuredMetadataBuilder::class, $firstStructuredBuilder);
        self::assertInstanceOf(StructuredMetadataBuilder::class, $secondStructuredBuilder);
        self::assertSame($firstStructuredBuilder, $secondStructuredBuilder);
    }

    #[Test]
    public function preservesPerMetadataStructuredResultsWhenBuilderIsShared(): void
    {
        $builder = new MetadataBuilder();
        $first   = $builder->withFileIdentity(extension: 'jpg')->build();
        $second  = $builder->withFileIdentity(extension: 'heic')->build();

        self::assertSame('jpg', $first->structured()->provenance->file->extension);
        self::assertSame('heic', $second->structured()->provenance->file->extension);
    }
}
