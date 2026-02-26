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
 * Enforces AGENTS.md §4 by forbidding @-suppressed binary I/O in critical parser/core paths.
 */
final class SuppressedBinaryIoGuardTest extends TestCase
{
    #[Test]
    public function listedBinaryIoFilesDoNotUseSuppressedFopenOrUnpack(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $files    = [
            'src/Core/Stream.php',
            'src/Core/Util/Unpack.php',
            'src/Parse/Icc/IccBinaryReader.php',
            'src/MakerNotes/Apple/PlistBinaryReader.php',
            'src/Parse/Jpeg/JpegFrameValidator.php',
            'src/Exif/Converters/MatrixConverter.php',
        ];

        $violations = [];
        foreach ($files as $relativePath) {
            $contents = file_get_contents($repoRoot . '/' . $relativePath);
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match('/@(?:fopen|unpack)\s*\(/', $contents) === 1) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame([], $violations, 'Suppressed binary I/O calls must be removed.');
    }
}
