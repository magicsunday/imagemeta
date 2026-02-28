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
 * Enforces explicit iconv failure handling for EXIF text decoding paths.
 */
final class SuppressedIconvGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function exifTextDecoderFiles(): array
    {
        return [
            'jis text decoder'        => ['src/Exif/Text/JisTextDecoder.php'],
            'ifd value reader'        => ['src/Exif/Model/IfdValueReader.php'],
            'gps timestamp converter' => ['src/Exif/Converters/GpsTimestampConverter.php'],
        ];
    }

    #[Test]
    #[DataProvider('exifTextDecoderFiles')]
    public function listedExifTextDecoderFilesDoNotUseSuppressedIconv(string $relativePath): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $contents = file_get_contents($repoRoot . '/' . $relativePath);

        self::assertIsString($contents);
        self::assertNotSame(
            1,
            preg_match('/@iconv\s*\(/', $contents),
            'Suppressed iconv calls must be removed.',
        );
    }
}
