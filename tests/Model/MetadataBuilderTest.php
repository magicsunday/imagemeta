<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model;

use Closure;
use MagicSunday\ImageMeta\Factory\StructuredMetadataBuilder;
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
final class MetadataBuilderTest extends TestCase
{
    #[Test]
    public function injectsStructuredResolverIntoBuiltMetadata(): void
    {
        $builder = new MetadataBuilder();
        $first   = $builder->withFileIdentity(extension: 'jpg')->build();
        $second  = $builder->withFileIdentity(extension: 'heic')->build();

        $resolverProperty = new ReflectionProperty(Metadata::class, 'structuredResolver');
        $firstResolver    = $resolverProperty->getValue($first);
        $secondResolver   = $resolverProperty->getValue($second);

        self::assertInstanceOf(Closure::class, $firstResolver);
        self::assertInstanceOf(Closure::class, $secondResolver);
        self::assertNotSame($firstResolver, $secondResolver);
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
