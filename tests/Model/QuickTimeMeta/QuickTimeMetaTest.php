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
 * Verifies the behaviour of the QuickTime metadata container model.
 *
 * @covers \MagicSunday\ImageMeta\Model\QuickTimeMeta
 */
final class QuickTimeMetaTest extends TestCase
{
    /**
     * Ensures the constructor stores the provided metadata map and the identifier is accessible.
     */
    #[Test]
    public function returnsConfiguredContentIdentifier(): void
    {
        $keys = [
            'com.apple.quicktime.content.identifier' => 'abc-123',
            'com.apple.quicktime.location.ISO6709'   => '+12.345-067.890/',
        ];

        $meta = new QuickTimeMeta($keys);

        self::assertSame($keys, $meta->keys);
        self::assertSame('abc-123', $meta->contentIdentifier());
    }

    /**
     * Ensures null is returned when the identifier key is missing.
     */
    #[Test]
    public function returnsNullWhenIdentifierIsMissing(): void
    {
        $meta = new QuickTimeMeta([
            'com.apple.quicktime.location.ISO6709' => '+12.345-067.890/',
        ]);

        self::assertNull($meta->contentIdentifier());
    }
}
