<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\IsoBmff;

use MagicSunday\ImageMeta\Parse\IsoBmff\AudioSampleEntryParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxNavigator;
use MagicSunday\ImageMeta\Parse\IsoBmff\BoxPayloadCollector;
use MagicSunday\ImageMeta\Parse\IsoBmff\IlocBoxParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParseContext;
use MagicSunday\ImageMeta\Parse\IsoBmff\IsoBmffParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemLocationResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\ItemPayloadResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeKeyResolver;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeMetadataDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\QuickTimeValueDecoder;
use MagicSunday\ImageMeta\Parse\IsoBmff\TrackMediaParser;
use MagicSunday\ImageMeta\Parse\IsoBmff\VideoSampleEntryParser;
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
#[UsesClass(AudioSampleEntryParser::class)]
#[UsesClass(BoxNavigator::class)]
#[UsesClass(BoxPayloadCollector::class)]
#[UsesClass(IlocBoxParser::class)]
#[UsesClass(IsoBmffParser::class)]
#[UsesClass(ItemLocationResolver::class)]
#[UsesClass(ItemPayloadResolver::class)]
#[UsesClass(QuickTimeKeyResolver::class)]
#[UsesClass(QuickTimeMetadataDecoder::class)]
#[UsesClass(QuickTimeValueDecoder::class)]
#[UsesClass(TrackMediaParser::class)]
#[UsesClass(VideoSampleEntryParser::class)]
final class IsoBmffParseContextTest extends TestCase
{
    /**
     * Creates a new parse context.
     * Verifies all shared parse state containers start empty.
     */
    #[Test]
    public function contextInitializesAllCollectionsAsEmptyArrays(): void
    {
        $context = new IsoBmffParseContext();

        self::assertSame(
            [
                'exifBlobs'       => [],
                'xmpBlobs'        => [],
                'qtKeys'          => [],
                'itemReferences'  => [],
                'dataReferences'  => [],
                'unresolvedItems' => [],
                'xmpHashes'       => [],
                'qtDataAtoms'     => [],
                'queuedUuidXmp'   => [],
            ],
            [
                'exifBlobs'       => $context->exifBlobs,
                'xmpBlobs'        => $context->xmpBlobs,
                'qtKeys'          => $context->qtKeys,
                'itemReferences'  => $context->itemReferences,
                'dataReferences'  => $context->dataReferences,
                'unresolvedItems' => $context->unresolvedItems,
                'xmpHashes'       => $context->xmpHashes,
                'qtDataAtoms'     => $context->qtDataAtoms,
                'queuedUuidXmp'   => $context->queuedUuidXmp,
            ],
        );
        self::assertSame(0, $context->moovCount);
        self::assertFalse($context->allowQuickTimeMetaWithoutFullBox);
    }

    /**
     * Inspects parser private method signatures after context refactor.
     * Confirms methods now accept an IsoBmffParseContext parameter instead of repeated refs.
     */
    #[Test]
    public function parserPrivateParseMethodsAcceptSharedContextParameter(): void
    {
        $parserClass               = new ReflectionClass(IsoBmffParser::class);

        $parserTwoParameterMethods = [
            'parseMoovBox',
            'parseMoofBox',
        ];

        foreach ($parserTwoParameterMethods as $methodName) {
            $method = $parserClass->getMethod($methodName);
            self::assertCount(2, $method->getParameters());
            self::assertSame(IsoBmffParseContext::class, (string) $method->getParameters()[1]->getType());
        }

        $metaMethod                = $parserClass->getMethod('parseMetaBox');
        self::assertCount(3, $metaMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $metaMethod->getParameters()[1]->getType());

        $udtaMethod                = $parserClass->getMethod('parseUdtaBox');
        self::assertCount(3, $udtaMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $udtaMethod->getParameters()[1]->getType());

        // parseTrak and parseMdia now live on TrackMediaParser
        $trackClass                = new ReflectionClass(TrackMediaParser::class);

        $trackMethod               = $trackClass->getMethod('parseTrak');
        self::assertCount(2, $trackMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $trackMethod->getParameters()[1]->getType());

        $mdiaMethod                = $trackClass->getMethod('parseMdia');
        self::assertCount(2, $mdiaMethod->getParameters());
        self::assertSame(IsoBmffParseContext::class, (string) $mdiaMethod->getParameters()[1]->getType());
    }
}
