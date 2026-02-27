#!/usr/bin/env php
<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use MagicSunday\ImageMeta\MetadataReader;

// Autoload
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
 * Validates that MetadataReader can process every file in a directory without error.
 *
 * Recursively scans the given directory for image/video files and calls
 * MetadataReader::read() on each. On success: silent. On failure: prints
 * the file path and error message.
 *
 * Usage:
 *   php scripts/validate-test-images.php <directory|file>
 *
 * Examples:
 *   php scripts/validate-test-images.php test-images/
 *   php scripts/validate-test-images.php test-images/exiftool/FujiFilm/
 *   php scripts/validate-test-images.php test-images/sample.jpg
 *
 * Exit codes:
 *   0 - All files parsed successfully
 *   1 - One or more files failed
 *   2 - Usage error (missing argument, invalid directory)
 */

// File extensions to skip (non-image sidecar files)
const EXCLUDED_EXTENSIONS = [
    'xmp' => true,
];

if ($argc < 2) {
    fprintf(STDERR, "Usage: php %s <directory|file>\n", $argv[0]);
    exit(2);
}

$input = rtrim($argv[1], '/');

if (!is_dir($input) && !is_file($input)) {
    fprintf(STDERR, "Error: Not a file or directory: %s\n", $input);
    exit(2);
}

// Collect all files and determine max path length for aligned output
$files      = [];
$maxPathLen = 0;

if (is_file($input)) {
    $absolutePath = realpath($input);
    $relativePath = basename($absolutePath);
    $maxPathLen   = strlen($relativePath);
    $files[]      = [$absolutePath, $relativePath];
} else {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($input, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $fileInfo */
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());

        if (isset(EXCLUDED_EXTENSIONS[$extension])) {
            continue;
        }

        $absolutePath = $fileInfo->getPathname();
        $relativePath = substr($absolutePath, strlen($input) + 1);
        $len          = strlen($relativePath);

        if ($len > $maxPathLen) {
            $maxPathLen = $len;
        }

        $files[] = [$absolutePath, $relativePath];
    }
}

// Process files — output failures immediately
$startTime = hrtime(true);
$reader    = MetadataReader::createDefault();
$total     = 0;
$failed    = 0;

echo "\n";

foreach ($files as [$absolutePath, $relativePath]) {
    ++$total;

    $fileStart = hrtime(true);

    try {
        $reader->read($absolutePath);
    } catch (Throwable $throwable) {
        ++$failed;
        $fileMs = (hrtime(true) - $fileStart) / 1e6;
        fprintf(STDERR, "  %-{$maxPathLen}s  %6.1fms  %s\n", $relativePath, $fileMs, $throwable->getMessage());
    }
}

// Summary
$elapsed = (hrtime(true) - $startTime) / 1e9;
$passed  = $total - $failed;
echo "\n";

$avgMs = $total > 0 ? ($elapsed / $total) * 1000 : 0;

if ($failed === 0) {
    echo sprintf("  ✔ %d/%d files passed (%.1fs, Ø %.1fms)\n", $passed, $total, $elapsed, $avgMs);
} else {
    fprintf(STDERR, sprintf("  ✘ %d/%d files passed, %d failed (%.1fs, Ø %.1fms)\n", $passed, $total, $failed, $elapsed, $avgMs));
}

echo "\n";

exit($failed > 0 ? 1 : 0);
