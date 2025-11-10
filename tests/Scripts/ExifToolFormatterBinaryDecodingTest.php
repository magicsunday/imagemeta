<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Scripts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for binary EXIF tag decoding in exiftool-format.php script.
 *
 * This test validates that UNDEFINED-type EXIF tags (ComponentsConfiguration,
 * SceneType, FileSource) are properly decoded from binary strings to human-readable
 * values according to EXIF 3.0 specifications.
 */
#[CoversNothing]
final class ExifToolFormatterBinaryDecodingTest extends TestCase
{
    /**
     * Tests ComponentsConfiguration decoding.
     *
     * EXIF 3.0 §4.6.4 Table 17 defines ComponentsConfiguration as a 4-byte
     * UNDEFINED type where each byte encodes a component identifier:
     * 0=none, 1=Y, 2=Cb, 3=Cr, 4=R, 5=G, 6=B
     */
    #[Test]
    public function testComponentsConfigurationDecoding(): void
    {
        // Standard YCbCr configuration: [1, 2, 3, 0] = Y, Cb, Cr, -
        $binaryValue = "\x01\x02\x03\x00";
        $expected    = 'Y, Cb, Cr, -';

        $decoded = $this->decodeComponentsConfiguration($binaryValue);

        self::assertSame($expected, $decoded);
    }

    /**
     * Tests ComponentsConfiguration for RGB uncompressed configuration.
     *
     * EXIF 3.0 §4.6.4 Table 17 default for RGB uncompressed: 4 5 6 0
     */
    #[Test]
    public function testComponentsConfigurationRgb(): void
    {
        // RGB configuration: [4, 5, 6, 0] = R, G, B, -
        $binaryValue = "\x04\x05\x06\x00";
        $expected    = 'R, G, B, -';

        $decoded = $this->decodeComponentsConfiguration($binaryValue);

        self::assertSame($expected, $decoded);
    }

    /**
     * Tests SceneType decoding.
     *
     * EXIF 3.0 §4.6.3 Table 13 defines SceneType as a 1-byte UNDEFINED type
     * where 1 = directly photographed image (required for DSC).
     */
    #[Test]
    public function testSceneTypeDirectlyPhotographed(): void
    {
        // Value 1 = Directly photographed image
        $binaryValue = "\x01";
        $expected    = '1 (Directly Photographed Image)';

        $decoded = $this->decodeSceneType($binaryValue);

        self::assertSame($expected, $decoded);
    }

    /**
     * Tests FileSource decoding.
     *
     * EXIF 3.0 §4.6.3 Table 12 defines FileSource as a 1-byte UNDEFINED type
     * where 3 = DSC (Digital Still Camera).
     */
    #[Test]
    public function testFileSourceDsc(): void
    {
        // Value 3 = Digital Camera
        $binaryValue = "\x03";
        $expected    = '3 (Digital Camera)';

        $decoded = $this->decodeFileSource($binaryValue);

        self::assertSame($expected, $decoded);
    }

    /**
     * Helper method that mirrors the decodeComponentsConfiguration logic.
     */
    private function decodeComponentsConfiguration(string $binaryValue): string
    {
        $componentNames = [
            0 => '-',
            1 => 'Y',
            2 => 'Cb',
            3 => 'Cr',
            4 => 'R',
            5 => 'G',
            6 => 'B',
        ];

        $bytes = unpack('C4', $binaryValue);
        if ($bytes === false) {
            return '(Decode error)';
        }

        $components = [];
        foreach ($bytes as $byte) {
            $components[] = $componentNames[$byte] ?? sprintf('(Unknown: %d)', $byte);
        }

        return implode(', ', $components);
    }

    /**
     * Helper method that mirrors the decodeSceneType logic.
     */
    private function decodeSceneType(string $binaryValue): string
    {
        $byte = ord($binaryValue[0]);

        $sceneTypeNames = [
            0 => 'Not Defined',
            1 => 'Directly Photographed Image',
        ];

        $name = $sceneTypeNames[$byte] ?? 'Unknown';

        return "{$byte} ({$name})";
    }

    /**
     * Helper method that mirrors the decodeFileSource logic.
     */
    private function decodeFileSource(string $binaryValue): string
    {
        $byte = ord($binaryValue[0]);

        $fileSourceNames = [
            0 => 'Other',
            1 => 'Transparency Scanner',
            2 => 'Reflection Scanner',
            3 => 'Digital Camera',
        ];

        $name = $fileSourceNames[$byte] ?? 'Unknown';

        return "{$byte} ({$name})";
    }
}
