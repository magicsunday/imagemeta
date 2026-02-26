<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_string;
use function preg_match;

/**
 * Enforces explicit iconv failure handling for EXIF text decoding paths.
 */
final class SuppressedIconvGuardTest extends TestCase
{
    #[Test]
    public function listedExifTextDecoderFilesDoNotUseSuppressedIconv(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $files    = [
            'src/Exif/Text/JisTextDecoder.php',
            'src/Exif/Model/IfdValueReader.php',
            'src/Exif/Converters/GpsTimestampConverter.php',
        ];

        $violations = [];
        foreach ($files as $relativePath) {
            $contents = file_get_contents($repoRoot . '/' . $relativePath);
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match('/@iconv\s*\(/', $contents) === 1) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame([], $violations, 'Suppressed iconv calls must be removed.');
    }
}
