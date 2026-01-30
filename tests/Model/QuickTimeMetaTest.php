<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\imagemeta\tests\Model;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the QuickTime metadata container model.
 * */
#[CoversClass(QuickTimeMeta::class)]
final class QuickTimeMetaTest extends TestCase
{
    /**
     * Verifies that $meta->contentIdentifier() equals $identifier.
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
     * Verifies that $meta->contentIdentifier() is null.
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
     * Verifies that $meta->stringValue(QuickTimeMeta::COMPRESSOR_NAME_KEY) equals 'H.265'.
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
     * Verifies that $meta->stringValue('UnknownKey') is null.
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
