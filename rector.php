<?php

/**
 * This file is part of the package magicsunday/jsonmapper.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Plus\RemoveDeadZeroAndOneOperationRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src/',
        __DIR__ . '/tests/',
    ]);

    if (
        !is_dir($concurrentDirectory = __DIR__ . '/.build/cache/.rector.cache')
        && !mkdir($concurrentDirectory, 0775, true)
        && !is_dir($concurrentDirectory)
    ) {
        throw new RuntimeException(
            sprintf(
                'Directory "%s" was not created',
                $concurrentDirectory
            )
        );
    }

    if (
        !is_dir($concurrentDirectory = __DIR__ . '/.build/cache/.rector.container.cache')
        && !mkdir($concurrentDirectory, 0775, true)
        && !is_dir($concurrentDirectory)
    ) {
        throw new RuntimeException(
            sprintf(
                'Directory "%s" was not created',
                $concurrentDirectory
            )
        );
    }

    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');
    $rectorConfig->cacheDirectory(__DIR__ . '/.build/cache/.rector.cache');
    $rectorConfig->containerCacheDirectory(__DIR__ . '/.build/cache/.rector.container.cache');

    // The shared rule sets and skips; 80400 is this package's PHP floor.
    (require __DIR__ . '/.build/vendor/magicsunday/coding-standard/rector/base.php')($rectorConfig, 80400);

    // Package-local skips on top of the shared ones.
    $rectorConfig->skip([
        // Explicit (new Foo())->method() parentheses kept for readability
        NewMethodCallWithoutParenthesesRector::class,
        // Intentional: $x * 1.0 casts int to float
        RemoveDeadZeroAndOneOperationRector::class,
    ]);
};
