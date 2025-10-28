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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExifVersionMatrixTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../Fixtures/Images/ExifVersions';

    #[Test]
    #[DataProvider('provideExifVersions')]
    public function readsExifVersionMatrix(
        string $fixture,
        string $expectedVersion,
        string $expectedProfile,
        string $expectedFlashpixVersion,
        ?array $expectedTiffEpStandardId,
        ?string $expectedTiffEpStandardString,
    ): void
    {
        $path = self::FIXTURE_DIR . '/' . $fixture;

        $structured = (new MetadataReader())->read($path)->structured();

        self::assertSame($expectedVersion, $structured->technical->standards->exifVersion);
        self::assertSame($expectedProfile, $structured->technical->standards->profile);
        self::assertSame($expectedFlashpixVersion, $structured->technical->standards->flashpixVersion);
        self::assertSame($expectedTiffEpStandardId, $structured->technical->standards->tiffEpStandardId);
        self::assertSame($expectedTiffEpStandardString, $structured->technical->standards->tiffEpStandardString);
    }

    /**
     * @return iterable<string, array{string,string,string,string,?list<int>,?string}>
     */
    public static function provideExifVersions(): iterable
    {
        yield '1.0' => ['exif-1-0.jpg', '1.00', '1.0', '1.00', null, null];
        yield '1.1' => ['exif-1-1.jpg', '1.10', '1.1', '1.00', null, null];
        yield '2.1' => ['exif-2-1.jpg', '2.10', '2.1', '1.00', null, null];
        yield '2.2' => ['exif-2-2.jpg', '2.20', '2.2', '1.00', null, null];
        yield '2.21' => ['exif-2-21.jpg', '2.21', '2.21', '1.00', null, null];
        yield '2.3' => ['exif-2-3.jpg', '2.30', '2.3', '1.00', [2, 0, 0, 0], '2.0.0.0'];
        yield '2.31' => ['exif-2-31.jpg', '2.31', '2.31', '1.00', [2, 0, 0, 1], '2.0.0.1'];
        yield '2.32' => ['exif-2-32.jpg', '2.32', '2.32', '1.00', [2, 0, 0, 2], '2.0.0.2'];
        yield '3.0' => ['exif-3-0.jpg', '3.00', '3.0', '1.00', [48, 49, 48, 48, 0], '0100'];
    }
}
