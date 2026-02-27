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
final class RedundantIntGuardCleanupTicket1821Test extends TestCase
{
    #[Test]
    public function doesNotContainRedundantIntGuardsFromTicket1821(): void
    {
        $this->assertSourceDoesNotContain(
            '/src/Parse/IsoBmff/BoxPayloadCollector.php',
            '$largeSize === 0 || $largeSize < 16',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/DngCalibrationValidator.php',
            '(is_int($illum1->value) && $illum1->value === 0) || (is_int($illum2->value) && $illum2->value === 0)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/DngGeometryValidator.php',
            '!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/DngProfileValidator.php',
            '!is_int($entry->value) || ($entry->value !== 0 && $entry->value !== 1)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/DngStructureValidator.php',
            '!$photo instanceof IfdEntry || !is_int($photo->value) || $photo->value !== 32803',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffColorInkValidator.php',
            'is_int($photometric->value) && ($photometric->value !== 5)',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffColorInkValidator.php',
            'is_int($photometric->value) && ($photometric->value === 5)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffTagConstraintValidator.php',
            '!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 3)',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffTagConstraintValidator.php',
            '!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 4)',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffTagConstraintValidator.php',
            '!($predictor instanceof IfdEntry) || !is_int($predictor->value) || ($predictor->value !== 2)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffExifTagValidator.php',
            '!($thumbCompression instanceof IfdEntry) || !is_int($thumbCompression->value) || ($thumbCompression->value !== 6)',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffExifTagValidator.php',
            '!($primaryCompression instanceof IfdEntry) || !is_int($primaryCompression->value) || ($primaryCompression->value !== 1)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffJpegThumbnailValidator.php',
            '!($compression instanceof IfdEntry) || !is_int($compression->value) || ($compression->value !== 6)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffJpegValidator.php',
            '(!is_int($jpegProcEntry->value) || !in_array($jpegProcEntry->value, [1, 14], true))',
        );

        $this->assertSourceDoesNotContain(
            '/src/Model/Xmp/XmpDocument.php',
            '$value instanceof XmpStructuredValue || !is_array($value)',
        );
    }

    private function assertSourceDoesNotContain(string $path, string $needle): void
    {
        $source = file_get_contents(__DIR__ . '/../..' . $path);
        self::assertIsString($source);
        self::assertStringNotContainsString($needle, $source);
    }
}
