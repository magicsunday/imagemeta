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
use MagicSunday\ImageMeta\Value\Enum\ResolutionUnit;
use MagicSunday\ImageMeta\Value\Enum\SceneType;
use MagicSunday\ImageMeta\Value\Enum\SensingMethod;
use MagicSunday\ImageMeta\Value\Enum\YCbCrPositioning;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function pack;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr_replace;

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

    private ReflectionMethod $printIccSectionMethod;

    private ReflectionMethod $calcScaleFactorTo35MmEquivalentMethod;

    private ReflectionMethod $calcFocalLength35MmEquivalentMethod;

    private ReflectionMethod $formatCompositeDateMethod;

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
        $this->printIccSectionMethod               = new ReflectionMethod($this->formatter, 'printIccSection');
        $this->calcScaleFactorTo35MmEquivalentMethod = new ReflectionMethod($this->formatter, 'calcScaleFactorTo35MmEquivalent');
        $this->calcFocalLength35MmEquivalentMethod = new ReflectionMethod($this->formatter, 'calcFocalLength35MmEquivalent');
        $this->formatCompositeDateMethod           = new ReflectionMethod($this->formatter, 'formatCompositeDate');
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

    #[Test]
    public function printsIndexedSectionsForMultipleIccProfiles(): void
    {
        $profileA = $this->buildMinimalIccProfile('Profile One');
        $profileB = $this->buildMinimalIccProfile('Profile Two');

        ob_start();
        $this->printIccSectionMethod->invoke($this->formatter, $profileA . $profileB);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('---- ICC-header ----', $output);
        self::assertStringContainsString('---- ICC_Profile ----', $output);
        self::assertStringContainsString('---- ICC-header2 ----', $output);
        self::assertStringContainsString('---- ICC_Profile2 ----', $output);
    }

    #[Test]
    public function calculatesScaleFactorTo35MmEquivalent(): void
    {
        $actual = $this->calcScaleFactorTo35MmEquivalentMethod->invoke($this->formatter, 24, 15.0);

        self::assertEqualsWithDelta(1.6, $actual, 0.0001);
    }

    #[Test]
    public function calculatesScaleFactorTo35MmEquivalentFromFocalPlaneData(): void
    {
        $actual = $this->calcScaleFactorTo35MmEquivalentMethod->invoke(
            $this->formatter,
            null,
            57.0,
            4438.356164383562,
            3888,
            ResolutionUnit::Inches->value,
        );

        self::assertEqualsWithDelta(1.61795, $actual, 0.0001);
    }

    #[Test]
    public function returnsNullScaleFactorTo35MmEquivalentForInvalidFocalPlaneWidth(): void
    {
        $actual = $this->calcScaleFactorTo35MmEquivalentMethod->invoke(
            $this->formatter,
            null,
            57.0,
            4438.356164383562,
            0,
            ResolutionUnit::Inches->value,
        );

        self::assertNull($actual);
    }

    #[Test]
    public function returnsNullScaleFactorTo35MmEquivalentForImplausibleCropFactor(): void
    {
        $actual = $this->calcScaleFactorTo35MmEquivalentMethod->invoke(
            $this->formatter,
            null,
            135.0,
            4438.356164383562,
            100,
            ResolutionUnit::Inches->value,
        );

        self::assertNull($actual);
    }

    #[Test]
    public function calculatesFocalLength35MmEquivalentFromScaleFactor(): void
    {
        $actual = $this->calcFocalLength35MmEquivalentMethod->invoke($this->formatter, 135.0, 1.6185185185);

        self::assertEqualsWithDelta(218.5, $actual, 0.01);
    }

    #[Test]
    public function formatsCompositeDateWithSubSeconds(): void
    {
        $actual = $this->formatCompositeDateMethod->invoke($this->formatter, '2008:05:30 15:56:01', '00');

        self::assertSame('2008:05:30 15:56:01.00', $actual);
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

    private function buildMinimalIccProfile(string $description): string
    {
        $descriptionText = $description . "\0";
        $descTagData     = 'desc' . pack('N', 0) . pack('N', strlen($descriptionText)) . $descriptionText;
        $descTagData     = str_pad($descTagData, (int) (((strlen($descTagData) + 3) / 4) * 4), "\0");

        $tagDataOffset = 128 + 4 + 12;
        $profileSize   = $tagDataOffset + strlen($descTagData);

        $header = str_repeat("\0", 128);
        $header = substr_replace($header, pack('N', $profileSize), 0, 4);
        $header = substr_replace($header, pack('N', 0x02400000), 8, 4);
        $header = substr_replace($header, 'RGB ', 16, 4);
        $header = substr_replace($header, 'XYZ ', 20, 4);
        $header = substr_replace($header, 'acsp', 36, 4);

        $tagTable = pack('N', 1)
            . 'desc'
            . pack('N', $tagDataOffset)
            . pack('N', strlen($descTagData));

        return $header . $tagTable . $descTagData;
    }
}
