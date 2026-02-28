<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;

/**
 * Enforces AGENTS.md §4 by forbidding @-suppressed binary I/O in critical parser/core paths.
 */
final class SuppressedBinaryIoGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function binaryIoFiles(): array
    {
        return [
            'core stream'          => ['src/Core/Stream.php'],
            'unpack util'          => ['src/Core/Util/Unpack.php'],
            'icc binary reader'    => ['src/Parse/Icc/IccBinaryReader.php'],
            'plist binary reader'  => ['src/MakerNotes/Apple/PlistBinaryReader.php'],
            'jpeg frame validator' => ['src/Parse/Jpeg/JpegFrameValidator.php'],
            'matrix converter'     => ['src/Exif/Converters/MatrixConverter.php'],
        ];
    }

    #[Test]
    #[DataProvider('binaryIoFiles')]
    public function listedBinaryIoFilesDoNotUseSuppressedFopenOrUnpack(string $relativePath): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $contents = file_get_contents($repoRoot . '/' . $relativePath);

        self::assertIsString($contents);
        self::assertNotSame(
            1,
            preg_match('/@(?:fopen|unpack)\s*\(/', $contents),
            'Suppressed binary I/O calls must be removed.',
        );
    }
}
