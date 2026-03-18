<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Exif\Factory;

use MagicSunday\ImageMeta\Exif\Factory\LensFactory;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Exif\Model\Ifd;
use MagicSunday\ImageMeta\Exif\Model\IfdEntry;
use MagicSunday\ImageMeta\Exif\Model\ParsedExif;
use MagicSunday\ImageMeta\Model\Metadata;
use MagicSunday\ImageMeta\Value\Lens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Exercises LensFactory for mapping EXIF lens tags into Lens values.
 * It verifies make, model, serial number, and focal length fields are mapped.
 * The suite covers max aperture and specification arrays derived from EXIF tags.
 * This ensures lens metadata is normalized consistently from EXIF inputs.
 *
 * @internal
 */
#[CoversClass(LensFactory::class)]
final class LensFactoryTest extends TestCase
{
    /**
     * Builds a ParsedExif instance with lens-related tags and numeric values.
     * Verifies LensFactory maps EXIF values into the lens data object fields.
     */
    #[Test]
    public function createsFromExifMetadata(): void
    {
        $lens = $this->createLens($this->parsedExif(
            lensMake: 'Canon',
            lensModel: 'RF 24-70mm F2.8 L IS USM',
            lensSerialNumber: '123456789',
            focalLengthMm: 50.0,
            focalLength35Mm: 50,
            maxApertureApex: 2.0,
            lensSpecification: [24.0, 70.0, 2.8, 2.8],
        ));

        self::assertSame('Canon', $lens->lensMake);
        self::assertSame('RF 24-70mm F2.8 L IS USM', $lens->lensModel);
        self::assertSame('123456789', $lens->lensSerialNumber);
        self::assertSame(50.0, $lens->focalLengthMm);
        self::assertSame(50, $lens->focalLengthIn35mm);
        self::assertSame(2.0, $lens->maxApertureFNumber);
        self::assertSame([24.0, 70.0, 2.8, 2.8], $lens->lensSpecification);
    }

    /**
     * Creates metadata without any EXIF document attached.
     * Ensures LensFactory returns a lens object with all fields left null.
     */
    #[Test]
    public function createsWithNullExifDoc(): void
    {
        $lens = $this->createLens(null);

        self::assertNull($lens->lensMake);
        self::assertNull($lens->lensModel);
        self::assertNull($lens->lensSerialNumber);
        self::assertNull($lens->focalLengthMm);
        self::assertNull($lens->focalLengthIn35mm);
        self::assertNull($lens->maxApertureFNumber);
        self::assertNull($lens->lensSpecification);
    }

    /**
     * Provides a maxApertureApex value without an explicit lens specification.
     * Confirms the factory converts the APEX value into an f-number.
     */
    #[Test]
    public function calculatesMaxApertureFromApex(): void
    {
        $lens = $this->createLens($this->parsedExif(
            lensMake: null,
            lensModel: null,
            lensSerialNumber: null,
            focalLengthMm: null,
            focalLength35Mm: null,
            maxApertureApex: 1.0,
            lensSpecification: null,
        ));

        self::assertNotNull($lens->maxApertureFNumber);
        self::assertEqualsWithDelta(1.4142135, $lens->maxApertureFNumber, 0.0001);
    }

    /**
     * Supplies lens identification fields but omits maxApertureApex.
     * Ensures the factory leaves maxApertureFNumber null while preserving other fields.
     */
    #[Test]
    public function handlesNullMaxApertureApex(): void
    {
        $lens = $this->createLens($this->parsedExif(
            lensMake: 'Sony',
            lensModel: 'FE 24-70mm F2.8 GM',
            lensSerialNumber: null,
            focalLengthMm: 35.0,
            focalLength35Mm: 35,
            maxApertureApex: null,
            lensSpecification: null,
        ));

        self::assertSame('Sony', $lens->lensMake);
        self::assertSame('FE 24-70mm F2.8 GM', $lens->lensModel);
        self::assertNull($lens->maxApertureFNumber);
    }

    /**
     * Supplies IFD entries with wrong TIFF types for lens make and model.
     * Verifies the factory degrades gracefully and returns null for mistyped fields.
     */
    #[Test]
    public function returnsNullFieldsWhenLensTagsHaveWrongTypes(): void
    {
        // Put SHORT integers where ASCII strings are expected
        $exifEntries = [
            ExifTag::LENS_MAKE => new IfdEntry(
                ExifTag::LENS_MAKE,
                3,
                1,
                42,
            ),
            ExifTag::LENS_MODEL => new IfdEntry(
                ExifTag::LENS_MODEL,
                3,
                1,
                99,
            ),
        ];

        $lens        = $this->createLens($this->parsedExifFromEntries($exifEntries));

        self::assertNull($lens->lensMake);
        self::assertNull($lens->lensModel);
    }

    /**
     * Supplies a truncated lens specification with only 2 rational values instead of 4.
     * Verifies the factory handles the incomplete data without crashing.
     */
    #[Test]
    public function handlesTruncatedLensSpecification(): void
    {
        $exifEntries = [
            ExifTag::LENS_SPECIFICATION => new IfdEntry(
                ExifTag::LENS_SPECIFICATION,
                5,
                2,
                [
                    [24, 1],
                    [70, 1],
                ],
            ),
        ];

        $this->createLens($this->parsedExifFromEntries($exifEntries));

        // A truncated specification should either be null or have fewer entries
        // — the key is that no exception is thrown
        $this->addToAssertionCount(1);
    }

    /**
     * @param array{0:float,1:float,2:float,3:float}|null $lensSpecification
     */
    private function parsedExif(
        ?string $lensMake,
        ?string $lensModel,
        ?string $lensSerialNumber,
        ?float $focalLengthMm,
        ?int $focalLength35Mm,
        ?float $maxApertureApex,
        ?array $lensSpecification,
    ): ParsedExif {
        $exifEntries = [];

        if ($lensMake !== null) {
            $exifEntries[ExifTag::LENS_MAKE] = new IfdEntry(
                ExifTag::LENS_MAKE,
                2,
                strlen($lensMake),
                $lensMake,
            );
        }

        if ($lensModel !== null) {
            $exifEntries[ExifTag::LENS_MODEL] = new IfdEntry(
                ExifTag::LENS_MODEL,
                2,
                strlen($lensModel),
                $lensModel,
            );
        }

        if ($lensSerialNumber !== null) {
            $exifEntries[ExifTag::LENS_SERIAL_NUMBER] = new IfdEntry(
                ExifTag::LENS_SERIAL_NUMBER,
                2,
                strlen($lensSerialNumber),
                $lensSerialNumber,
            );
        }

        if ($focalLengthMm !== null) {
            $exifEntries[ExifTag::FOCAL_LENGTH] = new IfdEntry(
                ExifTag::FOCAL_LENGTH,
                5,
                1,
                $focalLengthMm,
            );
        }

        if ($focalLength35Mm !== null) {
            $exifEntries[ExifTag::FOCAL_LENGTH_IN_35MM_FILM] = new IfdEntry(
                ExifTag::FOCAL_LENGTH_IN_35MM_FILM,
                3,
                1,
                $focalLength35Mm,
            );
        }

        if ($maxApertureApex !== null) {
            $exifEntries[ExifTag::MAX_APERTURE_VALUE] = new IfdEntry(
                ExifTag::MAX_APERTURE_VALUE,
                5,
                1,
                $maxApertureApex,
            );
        }

        if ($lensSpecification !== null) {
            $pairs                                    = [
                [$lensSpecification[0], 1],
                [$lensSpecification[1], 1],
                [$lensSpecification[2] * 10, 10],
                [$lensSpecification[3] * 10, 10],
            ];

            $exifEntries[ExifTag::LENS_SPECIFICATION] = new IfdEntry(
                ExifTag::LENS_SPECIFICATION,
                5,
                4,
                $pairs,
            );
        }

        return $this->parsedExifFromEntries($exifEntries);
    }

    private function createLens(?ParsedExif $parsedExif): Lens
    {
        $metadata = new Metadata(
            exifBlobs: [],
            quickTime: null,
            exifDoc: $parsedExif,
        );

        return new LensFactory()->create($metadata);
    }

    /**
     * @param array<int, IfdEntry> $exifEntries
     */
    private function parsedExifFromEntries(array $exifEntries): ParsedExif
    {
        $ifd0    = new Ifd([]);
        $exifIfd = new Ifd($exifEntries);

        return new ParsedExif(
            ifd0: $ifd0,
            exifIfd: $exifIfd,
            gpsIfd: null,
            interopIfd: null,
            ifd1: null,
        );
    }
}
