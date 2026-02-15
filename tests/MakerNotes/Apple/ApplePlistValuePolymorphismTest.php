<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistArray;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistDictionary;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistScalar;
use MagicSunday\ImageMeta\MakerNotes\Apple\ApplePlistValueInterface;
use MagicSunday\ImageMeta\MakerNotes\Apple\KeyedArchiveUnarchiver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies polymorphic keyed-archive resolution contract on plist value objects.
 * It ensures plist values expose resolveValue() and delegate resolution to the unarchiver.
 * This protects GRASP-polymorphism refactoring from regressions in type-dispatch logic.
 *
 * @internal
 */
#[CoversClass(ApplePlistValueInterface::class)]
#[UsesClass(ApplePlistArray::class)]
#[UsesClass(ApplePlistDictionary::class)]
#[UsesClass(ApplePlistScalar::class)]
#[UsesClass(KeyedArchiveUnarchiver::class)]
final class ApplePlistValuePolymorphismTest extends TestCase
{
    /**
     * Resolves a scalar plist value through the interface contract.
     * Confirms scalar values can dispatch via resolveValue() without instanceof checks.
     *
     * @return void
     */
    #[Test]
    public function scalarResolvesThroughPolymorphicContract(): void
    {
        $value      = new ApplePlistScalar('value');
        $unarchiver = new KeyedArchiveUnarchiver();

        $resolved = $value->resolveValue($unarchiver);

        self::assertSame($value, $resolved);
    }
}
