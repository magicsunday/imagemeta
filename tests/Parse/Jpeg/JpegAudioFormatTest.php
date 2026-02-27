<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JpegAudioFormatTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedAudioFormatValues(): void
    {
        $class = 'MagicSunday\\ImageMeta\\Parse\\Jpeg\\JpegAudioFormat';

        self::assertTrue(enum_exists($class));

        if (!enum_exists($class)) {
            return;
        }

        self::assertSame(0, $class::Pcm->value);
        self::assertSame(1, $class::MuLaw->value);
        self::assertSame(2, $class::ImaAdpcm->value);
    }
}

