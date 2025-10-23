<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Model\QuickTimeMeta;

use MagicSunday\ImageMeta\Model\QuickTimeMeta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test case for the QuickTime metadata container model.
 *
 * @covers \MagicSunday\ImageMeta\Model\QuickTimeMeta
 */
final class QuickTimeMetaTest extends TestCase
{
    /**
     * Ensures that the stored content identifier value is returned unchanged.
     */
    #[Test]
    public function returnsStoredContentIdentifier(): void
    {
        $identifier = 'abc-123';
        $keys       = [
            QuickTimeMeta::CONTENT_IDENTIFIER_KEY => $identifier,
            'com.apple.quicktime.location.ISO6709'   => '+12.345-067.890/',
        ];

        $meta = new QuickTimeMeta($keys);

        self::assertSame($identifier, $meta->contentIdentifier());
    }

    /**
     * Ensures null is returned when the content identifier key is absent.
     */
    #[Test]
    public function returnsNullWhenContentIdentifierIsMissing(): void
    {
        $meta = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+12.345-067.890/',
        ]);

        self::assertNull($meta->contentIdentifier());
    }
}
