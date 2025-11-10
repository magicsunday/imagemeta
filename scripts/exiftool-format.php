#!/usr/bin/env php
<?php

/**
 * ExifTool-like Output Formatter
 *
 * This script generates output similar to 'exiftool -H -a -u -g1' for comparing
 * metadata extraction results between this library and exiftool.
 *
 * Usage:
 *   php scripts/exiftool-format.php <image-file>
 *
 * Example:
 *   php scripts/exiftool-format.php photo.jpg
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Scripts;

use DateTimeInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Model\Exif\ExifNumericList;
use MagicSunday\ImageMeta\Model\Exif\ExifRational;
use MagicSunday\ImageMeta\Model\Exif\ExifRationalList;
use MagicSunday\ImageMeta\Model\Exif\ExifTag;
use MagicSunday\ImageMeta\Model\Exif\ParsedExif;
use MagicSunday\ImageMeta\Model\Tiff\TiffTag;

use function count;
use function date;
use function file_exists;
use function fileperms;
use function fileatime;
use function filectime;
use function filemtime;
use function filesize;
use function is_array;
use function is_numeric;
use function is_string;
use function number_format;
use function round;
use function sprintf;
use function str_pad;

// Check if we're using composer or standalone
$autoloadPaths = [
    __DIR__ . '/../.build/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

/**
 * Formats metadata in an exiftool-like output format.
 */
final class ExifToolFormatter
{
    private const string VERSION = '1.0.0';
    
    /**
     * Maps tag IDs to their human-readable names for EXIF tags.
     *
     * @var array<int, string>
     */
    private array $exifTagNames = [];
    
    /**
     * Maps tag IDs to their human-readable names for TIFF tags.
     *
     * @var array<int, string>
     */
    private array $tiffTagNames = [];

    public function __construct()
    {
        $this->buildTagMaps();
    }

    /**
     * Builds reverse mapping from tag IDs to tag names.
     */
    private function buildTagMaps(): void
    {
        // Build EXIF tag map
        $exifReflection = new \ReflectionClass(ExifTag::class);
        foreach ($exifReflection->getConstants() as $name => $value) {
            if (is_int($value)) {
                $this->exifTagNames[$value] = $this->constantNameToTagName($name);
            }
        }

        // Build TIFF tag map
        $tiffReflection = new \ReflectionClass(TiffTag::class);
        foreach ($tiffReflection->getConstants() as $name => $value) {
            if (is_int($value)) {
                $this->tiffTagNames[$value] = $this->constantNameToTagName($name);
            }
        }
    }

    /**
     * Converts constant name to tag name (e.g., GPS_LATITUDE_REF -> GPS Latitude Ref).
     */
    private function constantNameToTagName(string $constantName): string
    {
        // Remove GPS_ prefix if present
        if (str_starts_with($constantName, 'GPS_')) {
            $constantName = 'GPS ' . substr($constantName, 4);
        }
        
        // Convert SNAKE_CASE to Title Case
        $parts = explode('_', $constantName);
        $parts = array_map(function ($part) {
            return ucfirst(strtolower($part));
        }, $parts);
        
        return implode(' ', $parts);
    }

    /**
     * Formats the output for a given image file.
     */
    public function format(string $filePath): void
    {
        if (!file_exists($filePath)) {
            echo "Error: File not found: {$filePath}\n";
            exit(1);
        }

        $reader = new MetadataReader();
        $metadata = $reader->read($filePath, withDigests: true);

        // ExifTool section
        $this->printSection('ExifTool', [
            'ExifTool Version Number' => self::VERSION,
        ]);

        // System section
        $this->printSystemSection($filePath);

        // File section
        $this->printFileSection($metadata, $filePath);

        // IFD0 section
        if ($metadata->exifDoc !== null) {
            $this->printIfd0Section($metadata->exifDoc);
        }

        // ExifIFD section
        if ($metadata->exifDoc !== null && $metadata->exifDoc->exifIfd !== null) {
            $this->printExifIfdSection($metadata->exifDoc->exifIfd);
        }

        // MakerNotes section
        if ($metadata->makerNotes !== null) {
            $this->printMakerNotesSection($metadata->makerNotes);
        }

        // GPS section
        if ($metadata->exifDoc !== null && $metadata->exifDoc->gpsIfd !== null) {
            $this->printGpsSection($metadata->exifDoc->gpsIfd);
        }

        // XMP sections
        if ($metadata->xmpDoc !== null) {
            $this->printXmpSections($metadata->xmpDoc);
        }

        // ICC Profile section
        if ($metadata->iccProfile !== null) {
            $this->printIccSection($metadata->iccProfile);
        }

        // Composite section
        $this->printCompositeSection($metadata);
    }

    /**
     * Prints a section header and its data.
     *
     * @param array<string, mixed> $data
     */
    private function printSection(string $sectionName, array $data, bool $showHex = false): void
    {
        echo "---- {$sectionName} ----\n";
        
        foreach ($data as $key => $value) {
            $formattedValue = $this->formatValue($value);
            
            if ($showHex && is_numeric($key)) {
                $hexKey = sprintf('0x%04x', (int) $key);
                $tagName = $this->getTagName((int) $key);
                printf("%s %-30s: %s\n", $hexKey, $tagName, $formattedValue);
            } else {
                printf("     - %-30s: %s\n", $key, $formattedValue);
            }
        }
    }

    /**
     * Gets the tag name for a given tag ID.
     */
    private function getTagName(int $tagId): string
    {
        return $this->tiffTagNames[$tagId] 
            ?? $this->exifTagNames[$tagId] 
            ?? sprintf('Unknown 0x%04x', $tagId);
    }

    /**
     * Formats a value for display.
     */
    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '(none)';
        }

        if ($value instanceof ExifRational) {
            // Format as fraction or decimal
            if ($value->denominator === 0) {
                return 'inf';
            }
            
            $decimal = $value->numerator / $value->denominator;
            
            // If it's a simple fraction, show as fraction
            if ($value->denominator !== 1 && abs($decimal) < 10) {
                return sprintf('%d/%d', $value->numerator, $value->denominator);
            }
            
            return number_format($decimal, 6, '.', '');
        }

        if ($value instanceof ExifRationalList) {
            $parts = [];
            foreach ($value->rationals as $rational) {
                $parts[] = $this->formatValue($rational);
            }
            return implode(' ', $parts);
        }

        if ($value instanceof ExifNumericList) {
            $parts = [];
            foreach ($value->values as $num) {
                $parts[] = $this->formatValue($num);
            }
            return implode(' ', $parts);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y:m:d H:i:s');
        }

        if ($value instanceof \BackedEnum) {
            // For enums, use their value or name
            return $value->value ?? $value->name;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '(none)';
            }
            
            // Check if it's a simple numeric array
            if (array_is_list($value)) {
                return implode(' ', array_map(fn($v) => $this->formatValue($v), $value));
            }
            
            return json_encode($value);
        }

        if (is_float($value)) {
            // Clean up unnecessary decimals
            $formatted = number_format($value, 10, '.', '');
            return rtrim(rtrim($formatted, '0'), '.');
        }

        if (is_string($value)) {
            // Check for binary data
            if ($this->isBinary($value)) {
                return sprintf('(Binary data %d bytes)', strlen($value));
            }
            
            // Limit string length for display
            if (strlen($value) > 100) {
                return substr($value, 0, 100) . '...';
            }
            return $value;
        }

        return (string) $value;
    }

    /**
     * Checks if a string contains binary data.
     */
    private function isBinary(string $data): bool
    {
        // Check for null bytes or other control characters
        return preg_match('/[\x00-\x08\x0B-\x0C\x0E-\x1F]/', $data) === 1;
    }

    /**
     * Prints the System section with file system metadata.
     */
    private function printSystemSection(string $filePath): void
    {
        $fileName = basename($filePath);
        $directory = dirname($filePath);
        $fileSize = filesize($filePath);
        $modTime = filemtime($filePath);
        $accessTime = fileatime($filePath);
        $changeTime = filectime($filePath);
        $perms = fileperms($filePath);

        $sizeFormatted = $this->formatFileSize($fileSize);
        $permsFormatted = $this->formatPermissions($perms);

        $this->printSection('System', [
            'File Name' => $fileName,
            'Directory' => $directory,
            'File Size' => $sizeFormatted,
            'File Modification Date/Time' => date('Y:m:d H:i:sP', $modTime),
            'File Access Date/Time' => date('Y:m:d H:i:sP', $accessTime),
            'File Inode Change Date/Time' => date('Y:m:d H:i:sP', $changeTime),
            'File Permissions' => $permsFormatted,
        ]);
    }

    /**
     * Formats file size in human-readable format.
     */
    private function formatFileSize(int|false $bytes): string
    {
        if ($bytes === false) {
            return 'unknown';
        }

        $units = ['bytes', 'kB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }

    /**
     * Formats file permissions in Unix format.
     */
    private function formatPermissions(int|false $perms): string
    {
        if ($perms === false) {
            return 'unknown';
        }

        $info = '';

        // File type
        if (($perms & 0xC000) === 0xC000) {
            $info = 's'; // Socket
        } elseif (($perms & 0xA000) === 0xA000) {
            $info = 'l'; // Symbolic Link
        } elseif (($perms & 0x8000) === 0x8000) {
            $info = '-'; // Regular
        } elseif (($perms & 0x6000) === 0x6000) {
            $info = 'b'; // Block special
        } elseif (($perms & 0x4000) === 0x4000) {
            $info = 'd'; // Directory
        } elseif (($perms & 0x2000) === 0x2000) {
            $info = 'c'; // Character special
        } elseif (($perms & 0x1000) === 0x1000) {
            $info = 'p'; // FIFO pipe
        } else {
            $info = 'u'; // Unknown
        }

        // Owner permissions
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ?
            (($perms & 0x0800) ? 's' : 'x') :
            (($perms & 0x0800) ? 'S' : '-'));

        // Group permissions
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ?
            (($perms & 0x0400) ? 's' : 'x') :
            (($perms & 0x0400) ? 'S' : '-'));

        // Other permissions
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ?
            (($perms & 0x0200) ? 't' : 'x') :
            (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }

    /**
     * Prints the File section with container metadata.
     */
    private function printFileSection($metadata, string $filePath): void
    {
        $data = [
            'File Type' => $this->detectFileType($filePath),
            'File Type Extension' => $metadata->extension ?? 'unknown',
            'MIME Type' => $metadata->mimeType ?? 'unknown',
        ];

        // Add EXIF byte order if available
        if ($metadata->exifDoc !== null) {
            $endianness = $metadata->exifDoc->endianness ?? null;
            if ($endianness !== null) {
                $data['Exif Byte Order'] = $endianness->value === 'MM' 
                    ? 'Big-endian (Motorola, MM)' 
                    : 'Little-endian (Intel, II)';
            }
        }

        // Add image dimensions if available from JPEG or EXIF
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Width'] = $metadata->jpegFrameWidth;
            $data['Image Height'] = $metadata->jpegFrameHeight;
        }

        // Add JPEG-specific information
        if ($metadata->jpegBitsPerSample !== null) {
            $data['Bits Per Sample'] = $metadata->jpegBitsPerSample;
        }

        $this->printSection('File', $data);
    }

    /**
     * Detects file type from extension.
     */
    private function detectFileType(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($ext) {
            'jpg', 'jpeg' => 'JPEG',
            'heic' => 'HEIC',
            'heif' => 'HEIF',
            'avif' => 'AVIF',
            'mov' => 'MOV',
            'mp4' => 'MP4',
            default => strtoupper($ext),
        };
    }

    /**
     * Prints the IFD0 section.
     */
    private function printIfd0Section(ParsedExif $exif): void
    {
        $data = [];

        // Collect IFD0 tags from the ifd0 entries
        foreach ($exif->ifd0->entries as $tagId => $entry) {
            $data[$tagId] = $entry->value;
        }

        if (!empty($data)) {
            $this->printSection('IFD0', $data, showHex: true);
        }
    }

    /**
     * Prints the ExifIFD section.
     */
    private function printExifIfdSection($exifIfd): void
    {
        $data = [];

        // Collect ExifIFD tags
        if ($exifIfd !== null && isset($exifIfd->entries)) {
            foreach ($exifIfd->entries as $tagId => $entry) {
                $data[$tagId] = $entry->value;
            }
        }

        if (!empty($data)) {
            $this->printSection('ExifIFD', $data, showHex: true);
        }
    }

    /**
     * Prints the GPS section.
     */
    private function printGpsSection($gpsIfd): void
    {
        $data = [];

        // Collect GPS tags
        if ($gpsIfd !== null && isset($gpsIfd->entries)) {
            foreach ($gpsIfd->entries as $tagId => $entry) {
                $data[$tagId] = $entry->value;
            }
        }

        if (!empty($data)) {
            $this->printSection('GPS', $data, showHex: true);
        }
    }

    /**
     * Prints MakerNotes sections.
     */
    private function printMakerNotesSection($makerNotes): void
    {
        if ($makerNotes === null) {
            return;
        }

        // Determine vendor name
        $vendor = $makerNotes->vendor ?? 'Unknown';
        
        // For Apple maker notes, extract detailed information
        if ($makerNotes->apple !== null) {
            $data = [];
            $apple = $makerNotes->apple;
            
            // Use reflection to get all properties
            $reflection = new \ReflectionClass($apple);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
            
            foreach ($properties as $property) {
                $name = $property->getName();
                $value = $property->getValue($apple);
                
                if ($value !== null) {
                    // Convert property name to title case
                    $displayName = $this->propertyNameToDisplayName($name);
                    $data[$displayName] = $value;
                }
            }
            
            if (!empty($data)) {
                $this->printSection('Apple', $data);
            }
        }
    }

    /**
     * Converts property name to display name (camelCase to Title Case).
     */
    private function propertyNameToDisplayName(string $propertyName): string
    {
        // Insert space before uppercase letters
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $propertyName);
        
        // Capitalize first letter of each word
        return ucwords($spaced ?? $propertyName);
    }

    /**
     * Prints XMP sections.
     */
    private function printXmpSections($xmpDoc): void
    {
        if ($xmpDoc === null || !isset($xmpDoc->data)) {
            return;
        }

        // Group XMP data by namespace
        $grouped = [];
        
        foreach ($xmpDoc->data as $clarkNotation => $value) {
            // Clark notation format: {namespace}localName
            if (preg_match('/^\{([^}]+)\}(.+)$/', $clarkNotation, $matches)) {
                $namespace = $matches[1];
                $localName = $matches[2];
                
                // Simplify namespace for display
                $prefix = $this->namespaceToPrefix($namespace);
                
                if (!isset($grouped[$prefix])) {
                    $grouped[$prefix] = [];
                }
                
                $grouped[$prefix][$localName] = $value;
            }
        }
        
        // Print each namespace section
        foreach ($grouped as $prefix => $data) {
            if (!empty($data)) {
                $this->printSection("XMP-{$prefix}", $data);
            }
        }
    }

    /**
     * Converts XMP namespace URI to common prefix.
     */
    private function namespaceToPrefix(string $namespace): string
    {
        $prefixMap = [
            'http://ns.adobe.com/xap/1.0/' => 'xmp',
            'http://purl.org/dc/elements/1.1/' => 'dc',
            'http://ns.adobe.com/photoshop/1.0/' => 'photoshop',
            'http://ns.adobe.com/tiff/1.0/' => 'tiff',
            'http://ns.adobe.com/exif/1.0/' => 'exif',
            'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/' => 'Iptc4xmpCore',
            'http://www.metadataworkinggroup.com/schemas/regions/' => 'mwg-rs',
            'adobe:ns:meta/' => 'x',
        ];
        
        return $prefixMap[$namespace] ?? 'unknown';
    }

    /**
     * Prints ICC Profile section.
     */
    private function printIccSection(string $iccProfile): void
    {
        // Simplified ICC output
        $this->printSection('ICC-header', [
            'Profile Description' => '(Binary data)',
        ]);
    }

    /**
     * Prints the Composite section with derived values.
     */
    private function printCompositeSection($metadata): void
    {
        $structured = $metadata->structured();
        $data = [];

        // Aperture
        if ($structured->exposure->fNumber !== null) {
            $data['Aperture'] = $structured->exposure->fNumber;
        }

        // Image Size
        if ($metadata->jpegFrameWidth !== null && $metadata->jpegFrameHeight !== null) {
            $data['Image Size'] = sprintf(
                '%dx%d',
                $metadata->jpegFrameWidth,
                $metadata->jpegFrameHeight
            );
            $data['Megapixels'] = round(
                ($metadata->jpegFrameWidth * $metadata->jpegFrameHeight) / 1000000,
                1
            );
        }

        // Shutter Speed
        if ($structured->exposure->exposureTime !== null) {
            $data['Shutter Speed'] = $this->formatShutterSpeed($structured->exposure->exposureTime);
        }

        // GPS Position
        if ($structured->gps->latitude !== null && $structured->gps->longitude !== null) {
            $data['GPS Latitude'] = $this->formatGpsCoordinate(
                $structured->gps->latitude,
                $structured->gps->latitudeRef ?? 'N'
            );
            $data['GPS Longitude'] = $this->formatGpsCoordinate(
                $structured->gps->longitude,
                $structured->gps->longitudeRef ?? 'E'
            );
        }

        if (!empty($data)) {
            $this->printSection('Composite', $data);
        }
    }

    /**
     * Formats shutter speed as a fraction.
     */
    private function formatShutterSpeed(float $exposureTime): string
    {
        if ($exposureTime >= 1) {
            return number_format($exposureTime, 1);
        }

        $denominator = (int) round(1 / $exposureTime);
        return "1/{$denominator}";
    }

    /**
     * Formats GPS coordinate in degrees/minutes/seconds.
     */
    private function formatGpsCoordinate(float $decimal, string $ref): string
    {
        $degrees = (int) abs($decimal);
        $minutesFloat = (abs($decimal) - $degrees) * 60;
        $minutes = (int) $minutesFloat;
        $seconds = ($minutesFloat - $minutes) * 60;

        return sprintf(
            '%d deg %d\' %.2f" %s',
            $degrees,
            $minutes,
            $seconds,
            $ref
        );
    }
}

// Main execution
if ($argc < 2) {
    echo "Usage: php scripts/exiftool-format.php <image-file>\n";
    echo "\n";
    echo "Example:\n";
    echo "  php scripts/exiftool-format.php photo.jpg\n";
    exit(1);
}

$filePath = $argv[1];

try {
    $formatter = new ExifToolFormatter();
    $formatter->format($filePath);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
