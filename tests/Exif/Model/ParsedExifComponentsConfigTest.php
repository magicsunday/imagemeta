<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Model;

use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Exercises ComponentsConfiguration validation in ParsedExif.
 * It validates that only allowed component codes 0–6 are accepted per EXIF 3.0 §4.6.5.1.3.
 * The suite covers valid YCbCr and RGB configurations and rejects out-of-range codes.
 * This ensures component metadata is spec-compliant.
 *
 * @internal
 */
#[CoversClass(ParsedExif::class)]
final class ParsedExifComponentsConfigTest extends TestCase
{
    /**
     * Supplies the standard YCbCr configuration [1,2,3,0].
     * Confirms componentsConfiguration() returns the valid component codes.
     *
     * @return void
     */
    #[Test]
    public function acceptsStandardYcbcrConfiguration(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, 3, 0]);

        self::assertSame([1, 2, 3, 0], $parsedExif->componentsConfiguration());
    }

    /**
     * Supplies the RGB configuration [4,5,6,0].
     * Confirms componentsConfiguration() returns the valid component codes.
     *
     * @return void
     */
    #[Test]
    public function acceptsRgbConfiguration(): void
    {
        $parsedExif = $this->parsedExifWithComponents([4, 5, 6, 0]);

        self::assertSame([4, 5, 6, 0], $parsedExif->componentsConfiguration());
    }

    /**
     * Supplies a configuration with code 7, which is outside the defined range.
     * Confirms componentsConfiguration() rejects the non-conformant value.
     *
     * @return void
     */
    #[Test]
    public function rejectsCodeAboveSix(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, 3, 7]);

        self::assertNull($parsedExif->componentsConfiguration());
    }

    /**
     * Supplies a configuration with a negative code.
     * Confirms componentsConfiguration() rejects the non-conformant value.
     *
     * @return void
     */
    #[Test]
    public function rejectsNegativeCode(): void
    {
        $parsedExif = $this->parsedExifWithComponents([1, 2, -1, 0]);

        self::assertNull($parsedExif->componentsConfiguration());
    }

    /**
     * @param list<int> $components
     */
    private function parsedExifWithComponents(array $components): ParsedExif
    {
        $exifIfd = new Ifd([
            ExifTag::COMPONENTS_CONFIGURATION => new IfdEntry(
                ExifTag::COMPONENTS_CONFIGURATION,
                7,
                count($components),
                $components,
            ),
        ]);

        return new ParsedExif(new Ifd([]), $exifIfd, null, null, null);
    }
}
