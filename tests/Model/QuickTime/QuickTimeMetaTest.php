<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\QuickTime;

use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeDataAtom;
use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the QuickTimeMeta container for key-based metadata lookup.
 * It verifies content identifier and typed accessor helpers return normalized values.
 * The suite covers alias handling and numeric/boolean coercion.
 * This ensures QuickTime key/value metadata remains consistent for consumers.
 */
#[CoversClass(QuickTimeMeta::class)]
#[UsesClass(QuickTimeDataAtom::class)]
final class QuickTimeMetaTest extends TestCase
{
    /**
     * Exposes the stored content identifier when present.
     * It confirms the object preserves the supplied metadata.
     */
    #[Test]
    public function returnsStoredContentIdentifier(): void
    {
        $identifier = 'abc-123';
        $keys       = [
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY  => $identifier,
            'com.apple.quicktime.location.ISO6709' => '+12.345-067.890/',
        ];

        $meta       = new QuickTimeMeta($keys);

        self::assertSame($identifier, $meta->contentIdentifier());
    }

    /**
     * Returns null when the content identifier is missing.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function returnsNullWhenContentIdentifierIsMissing(): void
    {
        $meta = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+12.345-067.890/',
        ]);

        self::assertNull($meta->contentIdentifier());
    }

    /**
     * Resolves typed accessors and aliases for QuickTime metadata keys.
     * It exercises the scenario described by the test name.
     */
    #[Test]
    public function typedAccessorsResolveAliases(): void
    {
        $meta = new QuickTimeMeta([
            'Bitrate'                      => '512000',
            'HDRFormat'                    => 'true',
            'com.apple.quicktime.duration' => '12.5',
            'AudioChannels'                => 2,
            'CompressorName'               => '  H.265  ',
        ]);

        self::assertSame('H.265', $meta->stringValue(QuickTimeMeta::COMPRESSOR_NAME_KEY));
        self::assertSame(512000, $meta->intValue('Bitrate'));
        self::assertEqualsWithDelta(12.5, $meta->floatValue('Duration'), 1e-12);
        self::assertTrue($meta->boolValue('HDRFormat'));
        self::assertSame(2, $meta->intValue(QuickTimeMeta::AUDIO_CHANNELS_KEY));
    }

    /**
     * Returns null for missing keys across typed accessors.
     * It ensures missing or invalid inputs yield no value.
     */
    #[Test]
    public function typedAccessorsReturnNullForMissingKeys(): void
    {
        $meta = new QuickTimeMeta([]);

        self::assertNull($meta->stringValue('UnknownKey'));
        self::assertNull($meta->intValue('UnknownKey'));
        self::assertNull($meta->floatValue('UnknownKey'));
        self::assertNull($meta->boolValue('UnknownKey'));
    }

    /**
     * Returns all data atoms for a key via allValues().
     * It confirms multi-value data atoms are accessible in order.
     */
    #[Test]
    public function allValuesReturnsDataAtomsForKey(): void
    {
        $key    = QuickTimeMeta::CONTENT_IDENTIFIER_KEY;
        $atom1  = new QuickTimeDataAtom(1, 0, 'first');
        $atom2  = new QuickTimeDataAtom(1, 0x00010002, 'second');

        $meta   = new QuickTimeMeta(
            [$key => 'first'],
            [$key => [$atom1, $atom2]],
        );

        $values = $meta->allValues($key);

        self::assertCount(2, $values);
        self::assertSame($atom1, $values[0]);
        self::assertSame($atom2, $values[1]);
    }

    /**
     * Returns an empty list when no data atoms are present.
     * It ensures the default empty array is used when dataAtoms is omitted.
     */
    #[Test]
    public function allValuesReturnsEmptyForDefaultDataAtoms(): void
    {
        $meta = new QuickTimeMeta([]);

        self::assertSame([], $meta->allValues('UnknownKey'));
    }

    /**
     * Returns an empty list when requesting a key not in dataAtoms.
     * It verifies missing keys in the atom map yield no results.
     */
    #[Test]
    public function allValuesReturnsEmptyForMissingKey(): void
    {
        $atom = new QuickTimeDataAtom(1, 0, 'value');
        $meta = new QuickTimeMeta(
            [QuickTimeMeta::CONTENT_IDENTIFIER_KEY => 'value'],
            [QuickTimeMeta::CONTENT_IDENTIFIER_KEY => [$atom]],
        );

        self::assertSame([], $meta->allValues('NonExistentKey'));
    }

    /**
     * Selects the first acceptable atom in encounter order for deterministic fallback.
     */
    #[Test]
    public function firstAcceptableValueUsesEncounterOrder(): void
    {
        $key      = QuickTimeMeta::CONTENT_IDENTIFIER_KEY;
        $specific = new QuickTimeDataAtom(1, 0x555315C7, 'specific');
        $general  = new QuickTimeDataAtom(1, 0, 'general');
        $altType  = new QuickTimeDataAtom(7, 0, 'alt');
        $meta     = new QuickTimeMeta(
            [$key => 'specific'],
            [$key => [$specific, $general, $altType]],
        );

        self::assertSame(
            'specific',
            $meta->firstAcceptableValue($key, [0, 0x555315C7], [1]),
        );
        self::assertSame('general', $meta->firstAcceptableValue($key, [0], [1]));
        self::assertSame('alt', $meta->firstAcceptableValue($key, [0], [7]));
    }

    /**
     * Returns null when no data atom matches accepted locale/type values.
     */
    #[Test]
    public function firstAcceptableValueReturnsNullWhenNoAtomMatches(): void
    {
        $key  = QuickTimeMeta::CONTENT_IDENTIFIER_KEY;
        $meta = new QuickTimeMeta(
            [$key => 'fallback'],
            [$key => [new QuickTimeDataAtom(1, 0, 'fallback')]],
        );

        self::assertNull($meta->firstAcceptableAtom($key, [0x555315C7], [1]));
        self::assertNull($meta->firstAcceptableValue($key, [0x555315C7], [1]));
    }
}
