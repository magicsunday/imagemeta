<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\QuickTime;

use MagicSunday\ImageMeta\Model\QuickTime\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the QuickTimeMeta container for key-based metadata lookup.
 * It verifies content identifier and typed accessor helpers return normalized values.
 * The suite covers alias handling and numeric/boolean coercion.
 * This ensures QuickTime key/value metadata remains consistent for consumers.
 */
#[CoversClass(QuickTimeMeta::class)]
final class QuickTimeMetaTest extends TestCase
{
    /**
     * Exposes the stored content identifier when present.
     * It confirms the object preserves the supplied metadata.
     *
     * @return void
     */
    #[Test]
    public function returnsStoredContentIdentifier(): void
    {
        $identifier = 'abc-123';
        $keys       = [
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY  => $identifier,
            'com.apple.quicktime.location.ISO6709' => '+12.345-067.890/',
        ];

        $meta = new QuickTimeMeta($keys);

        self::assertSame($identifier, $meta->contentIdentifier());
    }

    /**
     * Returns null when the content identifier is missing.
     * It ensures missing or invalid inputs yield no value.
     *
     * @return void
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
     *
     * @return void
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
     *
     * @return void
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
}
