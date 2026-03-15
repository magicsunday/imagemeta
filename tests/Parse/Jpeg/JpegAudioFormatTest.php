<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Parse\Jpeg;

use MagicSunday\ImageMeta\Parse\Jpeg\JpegAudioFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the JpegAudioFormat enum exposes the expected audio format cases and values.
 */
final class JpegAudioFormatTest extends TestCase
{
    #[Test]
    public function enumExistsAndExposesExpectedAudioFormatValues(): void
    {
        $actual = [];

        foreach (JpegAudioFormat::cases() as $case) {
            $actual[$case->name] = $case->value;
        }

        self::assertSame(
            [
                'Pcm'      => 0x0,
                'MuLaw'    => 0x1,
                'ImaAdpcm' => 0x2,
            ],
            $actual,
        );
    }
}
