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
final class IdeInspectionCleanupTicket1822Test extends TestCase
{
    #[Test]
    public function removesTicket1822CodeSmells(): void
    {
        $this->assertSourceDoesNotContain(
            '/src/Parse/IsoBmff/BoxPayloadCollector.php',
            '$meta->contentSize < 20 ? $meta->contentSize : 20',
        );

        $this->assertSourceDoesNotContain(
            '/src/MetadataReader.php',
            '$probeLength = $stream->size();',
        );
        $this->assertSourceDoesNotContain(
            '/src/MetadataReader.php',
            'if ($probeLength > 8192)',
        );
        $this->assertSourceDoesNotContain(
            '/src/MetadataReader.php',
            '$chunkLength = $remaining > 8192 ? 8192 : $remaining;',
        );
        $this->assertSourceContains(
            '/src/MetadataReader.php',
            'private function parseEmbeddedExifBlobs',
        );

        $this->assertSourceDoesNotContain(
            '/src/MakerNotes/Apple/PlistTextParser.php',
            "if (\$terminator === ';' || \$terminator === ',') {\n                \$cursor->advance();\n                continue;\n            }",
        );
        $this->assertSourceDoesNotContain(
            '/src/MakerNotes/Apple/PlistTextParser.php',
            "if (\$terminator === '}') {\n                continue;\n            }",
        );
        $this->assertSourceDoesNotContain(
            '/src/MakerNotes/Apple/PlistTextParser.php',
            "if (\$terminator === ')') {\n                continue;\n            }",
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffJpegValidator.php',
            "if (!\$isJpegCompression) {\n                throw new ParseError('JPEGProc is only valid when Compression=6 (JPEG).', 1828);\n            }\n\n            return;",
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffJpegValidator.php',
            "if (!(\$lengthEntry instanceof IfdEntry) || !is_int(\$lengthEntry->value) || \$lengthEntry->value <= 0) {\n            return;\n        }",
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffTagConstraintValidator.php',
            'private function validatePositionRational(IfdEntry $entry, string $tagName): ExifRational',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/TiffTagConstraintValidator.php',
            'return $entry->value;',
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/IsoBmff/TrackMediaParser.php',
            'private function parseMdhd(BoxDescriptor $mdhd): int',
        );
        $this->assertSourceDoesNotContain(
            '/src/Parse/IsoBmff/TrackMediaParser.php',
            'return $timescale;',
        );

        $this->assertSourceDoesNotContain(
            '/src/Exif/Reader/IsoSensitivityReader.php',
            'resolve(includePrimaryThumbnail: true, includeIfd0: false)',
        );

        $this->assertSourceDoesNotContain(
            '/src/MakerNotes/Apple/ApplePlistArray.php',
            'public function __construct(private array $values)',
        );
        $this->assertSourceContains(
            '/src/MakerNotes/Apple/ApplePlistArray.php',
            'public function __construct(private readonly array $values)',
        );

        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'track_ref'         => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'track'             => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'img_direction_ref' => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'img_direction'     => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'dest_bearing_ref'  => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsDirectionConverter.php',
            "'dest_bearing'      => null,",
        );

        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'speed_ref'                  => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'speed_ms'                   => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'speed_original_ref'         => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'speed_original'             => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'dest_distance_ref'          => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'dest_distance_m'            => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'dest_distance_original_ref' => null,",
        );
        $this->assertSourceDoesNotContain(
            '/src/Exif/Converters/GpsUnitConverter.php',
            "'dest_distance_original'     => null,",
        );

        $this->assertSourceDoesNotContain(
            '/src/Value/SpatialFrequencyResponse.php',
            "foreach (\$parts->values as \$row) {\n            foreach (\$row as \$cell) {\n                if (\$cell === null) {\n                    return null;\n                }\n            }\n        }",
        );
        $this->assertSourceContains(
            '/src/Value/SpatialFrequencyResponse.php',
            "if (array_any(\$parts->values, static fn (array \$row): bool => in_array(null, \$row, true)))",
        );

        $this->assertSourceDoesNotContain(
            '/src/Parse/Tiff/ExifTagDecoder.php',
            "if (mb_check_encoding(\$text, 'UTF-8')) {\n                    return \$text;\n                }",
        );
        $this->assertSourceContains(
            '/src/Parse/Tiff/ExifTagDecoder.php',
            "if (mb_check_encoding(\$text, 'UTF-8')) {\n                return \$text;\n            }",
        );

        $this->assertSourceDoesNotContain(
            '/src/MakerNotes/Apple/KeyedArchiveResolver.php',
            "if (array_is_list(\$value)) {\n            foreach (\$value as \$entry) {",
        );
        $this->assertSourceContains(
            '/src/MakerNotes/Apple/KeyedArchiveResolver.php',
            'private function resolveNestedCandidateFromEntries',
        );
    }

    private function assertSourceDoesNotContain(string $path, string $needle): void
    {
        $source = file_get_contents(__DIR__ . '/../..' . $path);
        self::assertIsString($source);
        self::assertStringNotContainsString($needle, $source);
    }

    private function assertSourceContains(string $path, string $needle): void
    {
        $source = file_get_contents(__DIR__ . '/../..' . $path);
        self::assertIsString($source);
        self::assertStringContainsString($needle, $source);
    }
}
