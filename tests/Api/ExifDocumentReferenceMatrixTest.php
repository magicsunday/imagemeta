<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Api;

use MagicSunday\ImageMeta\Api\ExifDocument as ApiExifDocument;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Tests\Support\ExifExpectationAssertions;
use MagicSunday\ImageMeta\Tests\Support\ExifVersionExpectations;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifDocumentReferenceMatrixTest extends TestCase
{
    use ExifExpectationAssertions;

    #[Test]
    #[DataProviderExternal(ExifVersionExpectations::class, 'provideApi')]
    public function exposesFallbackMetadataFromReferenceImages(
        string $fixture,
        array $expectedApi,
    ): void {
        $metadata = (new MetadataReader())
            ->read(ExifVersionExpectations::path($fixture));

        $modelDocument = $metadata->exifDoc;
        self::assertNotNull($modelDocument, sprintf('Reference EXIF document missing for %s', $fixture));

        $document = new ApiExifDocument($modelDocument);

        self::assertSame($modelDocument, $document->raw(), sprintf('%s: Raw document reference', $fixture));

        self::assertApiMatches($fixture, $document, $expectedApi);
    }
}
