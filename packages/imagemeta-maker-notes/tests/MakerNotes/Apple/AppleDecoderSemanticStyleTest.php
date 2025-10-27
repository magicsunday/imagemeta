<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\AppleDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \MagicSunday\ImageMeta\MakerNotes\AppleDecoder
 */
final class AppleDecoderSemanticStyleTest extends TestCase
{
    #[Test]
    public function buildAppleMakerNotesExtractsSemanticStyleDictionary(): void
    {
        $decoder = new AppleDecoder();
        $method  = new ReflectionMethod(AppleDecoder::class, 'buildAppleMakerNotes');
        $method->setAccessible(true);

        $dictionary = [
            'SemanticStyle' => [
                '_0' => 'RichWarm',
                '_2' => 0.25,
                '_3' => -0.4,
            ],
        ];

        /** @var AppleMakerNotes|null $makerNotes */
        $makerNotes = $method->invoke($decoder, $dictionary);

        self::assertInstanceOf(AppleMakerNotes::class, $makerNotes);
        self::assertSame('RichWarm', $makerNotes->semanticStylePreset);
        self::assertSame(0.25, $makerNotes->semanticStyleWarmth);
        self::assertSame(-0.4, $makerNotes->semanticStyleTone);
    }
}
