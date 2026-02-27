<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * @internal
 */
final class DuplicateCodeTicket1823Test extends TestCase
{
    #[Test]
    public function usesGroupedImportForExifModelTypes(): void
    {
        $this->assertSourceContains(
            '/src/Exif/Reader/TiffBaselineExifReader.php',
            'use MagicSunday\\ImageMeta\\Exif\\Model\\{',
        );

        $this->assertSourceDoesNotContain(
            '/src/Exif/Reader/TiffBaselineExifReader.php',
            'use MagicSunday\\ImageMeta\\Exif\\Model\\ExifNumericList;',
        );
    }

    private function assertSourceContains(string $path, string $needle): void
    {
        $source = file_get_contents(__DIR__ . '/../..' . $path);
        self::assertIsString($source);
        self::assertStringContainsString($needle, $source);
    }

    private function assertSourceDoesNotContain(string $path, string $needle): void
    {
        $source = file_get_contents(__DIR__ . '/../..' . $path);
        self::assertIsString($source);
        self::assertStringNotContainsString($needle, $source);
    }
}
