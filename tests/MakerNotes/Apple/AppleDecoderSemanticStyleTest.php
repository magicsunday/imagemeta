<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Apple;

use MagicSunday\ImageMeta\MakerNotes\Apple\AppleCaptureIdentity;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleDictionaryValueExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleFlagExtractor;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleHdr;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotes;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleMakerNotesBuilder;
use MagicSunday\ImageMeta\MakerNotes\Apple\AppleSemanticStyle;
use MagicSunday\ImageMeta\MakerNotes\Apple\Support\SemanticStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises AppleDecoder handling of SemanticStyle maker note payloads.
 * It verifies preset, warmth, and tone fields are extracted from the dictionary.
 * The suite ensures SemanticStyle normalization feeds into AppleMakerNotes correctly.
 * This keeps portrait-style metadata stable for later consumers.
 *
 * @internal
 */
#[CoversClass(AppleMakerNotesBuilder::class)]
#[UsesClass(AppleDictionaryValueExtractor::class)]
#[UsesClass(AppleFlagExtractor::class)]
#[UsesClass(AppleMakerNotes::class)]
#[UsesClass(AppleSemanticStyle::class)]
#[UsesClass(SemanticStyle::class)]
#[UsesClass(AppleCaptureIdentity::class)]
#[UsesClass(AppleHdr::class)]
final class AppleDecoderSemanticStyleTest extends TestCase
{
    /**
     * Invokes buildAppleMakerNotes with a SemanticStyle dictionary payload.
     * Ensures semantic style preset, warmth, and tone fields are extracted.
     */
    #[Test]
    public function buildAppleMakerNotesExtractsSemanticStyleDictionary(): void
    {
        $builder = new AppleMakerNotesBuilder();

        $dictionary = [
            'SemanticStyle' => [
                '_0' => 'RichWarm',
                '_2' => 0.25,
                '_3' => -0.4,
            ],
        ];

        /** @var AppleMakerNotes|null $makerNotes */
        $makerNotes = $builder->build($dictionary);

        self::assertInstanceOf(AppleMakerNotes::class, $makerNotes);
        self::assertSame('RichWarm', $makerNotes->semanticStyle?->preset);
        self::assertSame(0.25, $makerNotes->semanticStyle->warmth);
        self::assertSame(-0.4, $makerNotes->semanticStyle->tone);
    }
}
