<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParseContext;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Exercises the shared ISO BMFF parse context object and parser method signatures.
 * It verifies context defaults and confirms parse methods consume context over repeated refs.
 * This protects the DRY refactor from regressions in private parser method contracts.
 *
 * @internal
 */
#[CoversClass(IsoBmffParseContext::class)]
#[UsesClass(IsoBmffParser::class)]
final class IsoBmffParseContextTest extends TestCase
{
    /**
     * Creates a new parse context.
     * Verifies all shared parse state containers start empty.
     *
     * @return void
     */
    #[Test]
    public function contextInitializesAllCollectionsAsEmptyArrays(): void
    {
        $context = new IsoBmffParseContext();

        self::assertSame([], $context->exifBlobs);
        self::assertSame([], $context->xmpBlobs);
        self::assertSame([], $context->qtKeys);
        self::assertSame([], $context->itemReferences);
        self::assertSame([], $context->dataReferences);
        self::assertSame([], $context->unresolvedItems);
        self::assertSame([], $context->xmpHashes);
        self::assertSame([], $context->qtDataAtoms);
        self::assertSame([], $context->queuedUuidXmp);
        self::assertSame(0, $context->moovCount);
        self::assertFalse($context->allowQuickTimeMetaWithoutFullBox);
    }

    /**
     * Inspects parser private method signatures after context refactor.
     * Confirms methods now accept an IsoBmffParseContext parameter instead of repeated refs.
     *
     * @return void
     */
    #[Test]
    public function parserPrivateParseMethodsAcceptSharedContextParameter(): void
    {
        $class = new ReflectionClass(IsoBmffParser::class);

        $twoParameterMethods = [
            'parseMoovBox',
            'parseMoofBox',
            'parseTrak',
            'parseMdia',
        ];

        foreach ($twoParameterMethods as $methodName) {
            $method = $class->getMethod($methodName);
            self::assertCount(2, $method->getParameters());
            self::assertSame(IsoBmffParseContext::class, (string) $method->getParameters()[1]->getType());
        }

        $metaMethod = $class->getMethod('parseMetaBox');
        self::assertCount(3, $metaMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $metaMethod->getParameters()[1]->getType());

        $udtaMethod = $class->getMethod('parseUdtaBox');
        self::assertCount(3, $udtaMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $udtaMethod->getParameters()[1]->getType());
    }
}
