<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type StructuredExpectations from \MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions
 */
final class ExifVersionMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @phpstan-param StructuredExpectations $expectedStructured
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideStructured')]
    public function matchesStructuredExpectations(string $fixture, array $expectedStructured): void
    {
        $metadata = (new MetadataReader())
            ->read(ExifVersionExpectations::path($fixture));

        self::assertStructuredMatches($fixture, $metadata, $expectedStructured);
    }
}
