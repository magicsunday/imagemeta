<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\MakerNotes\Registry;

use MagicSunday\ImageMeta\MakerNotes\MakerNotesDecoderInterface;
use MagicSunday\ImageMeta\MakerNotes\Registry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the maker notes decoder registry.
 *
 * @covers \MagicSunday\ImageMeta\MakerNotes\Registry
 */
final class RegistryTest extends TestCase
{
    /**
     * Ensures decoders can be registered and retrieved via case-insensitive prefix matching.
     */
    #[Test]
    public function findsDecoderByMakePrefix(): void
    {
        $decoder  = new class implements MakerNotesDecoderInterface {
            public function decode(string $raw, string $make, ?string $model): array
            {
                return ['decoder' => 'apple'];
            }
        };
        $registry = new Registry();
        $registry->register('Apple', $decoder);

        $match = $registry->find('APPLE iPhone 15 Pro');

        self::assertSame($decoder, $match);
    }

    /**
     * Ensures null is returned when no decoder prefix matches the make string.
     */
    #[Test]
    public function returnsNullWhenNoDecoderMatches(): void
    {
        $registry = new Registry();

        self::assertNull($registry->find('Unknown Manufacturer'));
    }
}
