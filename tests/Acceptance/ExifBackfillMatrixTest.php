<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Acceptance;

use MagicSunday\ImageMeta\Api\ExifDocument as ApiExifDocument;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type StructuredExpectations from \MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions
 * @phpstan-import-type ApiExpectations from \MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions
 * @phpstan-import-type ModelExpectations from \MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions
 */
final class ExifBackfillMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    /**
     * @phpstan-param StructuredExpectations $expectedStructured
     * @phpstan-param ApiExpectations        $expectedApi
     * @phpstan-param ModelExpectations      $expectedModel
     */
    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideAll')]
    public function extractsFallbackMetadataFromReferenceImages(
        string $fixture,
        array $expectedStructured,
        array $expectedApi,
        array $expectedModel,
    ): void {
        $metadata = (new MetadataReader())->read(ExifVersionExpectations::path($fixture));

        self::assertStructuredMatches($fixture, $metadata, $expectedStructured);

        $document = new ApiExifDocument($metadata->exifDoc);
        self::assertApiMatches($fixture, $document, $expectedApi);

        self::assertModelMatches($fixture, $metadata->exifDoc, $expectedModel);
    }
}
