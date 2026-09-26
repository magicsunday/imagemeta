<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('This script supports command line usage only. Please check your command.');
}

// The shared ruleset lives in magicsunday/coding-standard; this file only
// supplies the package's own file header and its finder.
$factory = require __DIR__ . '/.build/vendor/magicsunday/coding-standard/php-cs-fixer/base.php';

$config = $factory(<<<EOF
    This file is part of the package magicsunday/imagemeta.

    For the full copyright and license information, please read the
    LICENSE file that was distributed with this source code.
    EOF);

// Package-local exception: grouped imports stay allowed. TiffBaselineExifReader groups its
// Exif\Model imports so its import block no longer duplicates a sibling reader's, which jscpd
// (threshold 0) reported (ticket 1823; pinned by DuplicateCodeTicket1823Test).
$config->setRules([...$config->getRules(), 'single_import_per_statement' => false]);

return $config
    ->setCacheFile(__DIR__ . '/.build/cache/.php-cs-fixer.cache')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/src/',
                __DIR__ . '/tests/',
            ])
    );
