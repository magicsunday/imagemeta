<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests\Scripts;

use BackedEnum;
use MagicSunday\ImageMeta\Exif\Model\ExifNumericList;
use MagicSunday\ImageMeta\Exif\Model\ExifTag;
use MagicSunday\ImageMeta\Value\Enum\ColorSpace;
use MagicSunday\ImageMeta\Value\Enum\CustomRendered;
use MagicSunday\ImageMeta\Value\Enum\FlashMode;
use MagicSunday\ImageMeta\Value\Enum\GpsAltitudeRef;
use MagicSunday\ImageMeta\Value\Enum\MeteringMode;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies exiftool-like enum label rendering in the formatter script.
 */
#[CoversNothing]
final class MetadataFormatterEnumFormattingTest extends TestCase
{
    private object $formatter;

    private ReflectionMethod $formatEnumNameMethod;

    private ReflectionMethod $formatComponentsConfigurationMethod;

    private ReflectionMethod $getTagNameMethod;

    private ReflectionMethod $printSectionMethod;

    private ReflectionMethod $formatValueMethod;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../scripts/imagemeta-format.php';

        $formatterReflection = new ReflectionMethod('MagicSunday\\ImageMeta\\Scripts\\MetadataFormatter', 'format');
        $this->formatter     = $formatterReflection->getDeclaringClass()->newInstance();

        $this->formatEnumNameMethod                = new ReflectionMethod($this->formatter, 'formatEnumName');
        $this->formatComponentsConfigurationMethod = new ReflectionMethod($this->formatter, 'formatComponentsConfiguration');
        $this->getTagNameMethod                    = new ReflectionMethod($this->formatter, 'getTagName');
        $this->printSectionMethod                  = new ReflectionMethod($this->formatter, 'printSection');
        $this->formatValueMethod                   = new ReflectionMethod($this->formatter, 'formatValue');
    }

    #[Test]
    #[DataProvider('provideKnownEnums')]
    public function omitsRawPrefixForKnownEnums(BackedEnum $value, string $expected): void
    {
        $actual = $this->formatValueMethod->invoke($this->formatter, $value);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{0:BackedEnum, 1:string}>
     */
    public static function provideKnownEnums(): iterable
    {
        yield 'Metering average' => [MeteringMode::Average, 'Average'];
        yield 'Metering pattern' => [MeteringMode::Pattern, 'Multi-segment'];
        yield 'Custom rendered normal process' => [CustomRendered::NormalProcess, 'Normal'];
        yield 'Color space sRGB' => [ColorSpace::Srgb, 'sRGB'];
        yield 'YCbCr co-sited' => [YCbCrPositioning::CoSited, 'Co-sited'];
        yield 'Sensing one-chip' => [SensingMethod::OneChipColorArea, 'One-chip Color Area'];
        yield 'GPS altitude sea level label' => [GpsAltitudeRef::AboveEllipsoidalSurface, 'Above Sea Level'];
        yield 'Scene type directly photographed' => [SceneType::DirectlyPhotographedImage, 'Directly Photographed'];
        yield 'Flash mode compulsory fire' => [FlashMode::CompulsoryFire, 'Compulsory Fire'];
    }

    #[Test]
    #[DataProvider('provideSpecialEnumNames')]
    public function formatsSpecialEnumNames(string $enumName, string $expected): void
    {
        $actual = $this->formatEnumNameMethod->invoke($this->formatter, $enumName);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function provideSpecialEnumNames(): iterable
    {
        yield 'sRGB casing' => ['Srgb', 'sRGB'];
        yield 'Flash return spacing' => ['NoStrobeDetection', 'No Strobe Detection'];
        yield 'Flash mode spacing' => ['CompulsorySuppress', 'Compulsory Suppress'];
        yield 'Legacy snake case conversion' => ['AUTO_BRACKET', 'Auto Bracket'];
    }

    #[Test]
    public function keepsRawNumericValueForUnmappedEnums(): void
    {
        $actual = $this->formatValueMethod->invoke($this->formatter, 999);

        self::assertSame('999', $actual);
    }

    #[Test]
    public function formatsGpsVersionIdWithDots(): void
    {
        $actual = $this->formatValueMethod->invoke($this->formatter, [2, 2, 0, 0], 'GPS', ExifTag::GPS_VERSION_ID);

        self::assertSame('2.2.0.0', $actual);
    }

    #[Test]
    public function formatsGpsVersionIdNumericListWithDots(): void
    {
        $actual = $this->formatValueMethod->invoke(
            $this->formatter,
            new ExifNumericList([2, 2, 0, 0]),
            'GPS',
            ExifTag::GPS_VERSION_ID,
        );

        self::assertSame('2.2.0.0', $actual);
    }

    #[Test]
    public function resolvesInteroperabilityVersionTagNameInInteropIfd(): void
    {
        $actual = $this->getTagNameMethod->invoke($this->formatter, 0x0002, 'InteropIFD');

        self::assertSame('Interoperability Version', $actual);
    }

    #[Test]
    public function suppressesIfdPointerTagsInFormattedOutput(): void
    {
        ob_start();
        $this->printSectionMethod->invoke(
            $this->formatter,
            'IFD0',
            [
                ExifTag::EXIF_IFD_POINTER             => 214,
                ExifTag::GPS_IFD_POINTER              => 978,
                ExifTag::INTEROPERABILITY_IFD_POINTER => 948,
                ExifTag::MAKE                         => 'Canon',
            ],
            true,
        );
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString('Exif IFD Pointer', $output);
        self::assertStringNotContainsString('GPS IFD Pointer', $output);
        self::assertStringNotContainsString('Interoperability Ifd Pointer', $output);
        self::assertStringContainsString('Make', $output);
    }

    /**
     * @param list<string>|null $labels
     */
    #[Test]
    #[DataProvider('provideComponentsConfigurations')]
    public function formatsComponentsConfigurationInExifOrder(?array $labels, ?string $expected): void
    {
        $actual = $this->formatComponentsConfigurationMethod->invoke($this->formatter, $labels);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{0:?array<int, string>, 1:?string}>
     */
    public static function provideComponentsConfigurations(): iterable
    {
        yield 'YCbCr with implied absent fourth component' => [['Y', 'Cb', 'Cr'], 'Y, Cb, Cr, -'];
        yield 'Full RGBA-style configuration already complete' => [['R', 'G', 'B', '-'], 'R, G, B, -'];
        yield 'Null configuration stays null' => [null, null];
    }
}
