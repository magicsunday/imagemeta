<?php

/**
 * This file is part of the package magicsunday/imagemeta.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\ImageMeta\Tests;

use BackedEnum;
use DateTimeInterface;
use MagicSunday\ImageMeta\MetadataReader;
use MagicSunday\ImageMeta\Tests\Truth\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnitEnum;

/**
 * Normalize the provided value to an ISO-8601 string when possible.
 */
function safeIso(DateTimeInterface|string|null $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d\TH:i:sP');
    }
    if ($value === null || $value === '' || !str_contains($value, 'T')) {
        return null;
    }

    $normalized = preg_replace('/\.\d+(?=[Z\+\-]|$)/', '', $value);
    if ($normalized === null) {
        return $value;
    }

    if (preg_match('/([\+\-]\d{2})(\d{2})$/', $normalized, $matches) === 1) {
        $normalized = substr($normalized, 0, -5) . $matches[1] . ':' . $matches[2];
    }

    return $normalized;
}

/**
 * Resolve the enum or string value to a comparable enum name.
 */
function enumName(BackedEnum|UnitEnum|string|null $enum): ?string
{
    if ($enum instanceof BackedEnum || $enum instanceof UnitEnum) {
        return $enum->name;
    }
    return is_string($enum) ? $enum : null;
}

/**
 * Verify that structured metadata aligns with ExifTool truth data fixtures.
 */
final class TruthComparisonTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';
    private const IMAGES   = __DIR__ . '/Fixtures/Images';

    private Normalizer $norm;

    /** @var array<string, array<int|string, string>> */
    private array $map;

    /**
     * Prepare the enum map and normalizer instance for each test.
     */
    protected function setUp(): void
    {
        /** @var array<string, array<int|string, string>> $map */
        $map = require __DIR__ . '/Truth/EnumMap.php';
        $this->map  = $map;
        $this->norm = new Normalizer($map);
    }

    #[Test]
    #[DataProvider('provideFiles')]
    public function test_core_fields_match_exiftool(string $file): void
    {
        $exif = $this->loadExifToolJson($file);

        $meta = (new MetadataReader())
            ->read(self::IMAGES . '/' . $file)
            ->structured();

        // Camera information
        $this->assertSame($exif['IFD0:Make'] ?? null, $meta->camera->make ?? null, "$file: Make");
        $this->assertSame($exif['IFD0:Model'] ?? null, $meta->camera->model ?? null, "$file: Model");
        $this->assertSame($exif['IFD0:Software'] ?? null, $meta->camera->firmware ?? null, "$file: Firmware");

        // Image dimensions
        $this->assertSame((int)($exif['File:ImageWidth'] ?? 0), $meta->image->width ?? 0, "$file: width");
        $this->assertSame((int)($exif['File:ImageHeight'] ?? 0), $meta->image->height ?? 0, "$file: height");

        // Orientation
        if (isset($exif['IFD0:Orientation'], $this->map['Orientation'])) {
            $ok = $this->norm->compareEnum('Orientation', (int)$exif['IFD0:Orientation'], $meta->image->orientation ?? null);
            $this->assertTrue($ok, "$file: Orientation enum mapping");
        }

        // ColorSpace
        if (isset($exif['EXIF:ColorSpace'], $this->map['ColorSpace'])) {
            $ok = $this->norm->compareEnum('ColorSpace', (int)$exif['EXIF:ColorSpace'], $meta->image->colorSpace ?? null);
            $this->assertTrue($ok, "$file: ColorSpace enum mapping");
        }

        // Core EXIF exposure data
        $this->assertEqualsWithDelta((float)($exif['EXIF:FNumber'] ?? 0), (float)($meta->exposure->fNumber ?? 0), Normalizer::DELTA, "$file: FNumber");
        $this->assertEqualsWithDelta((float)($exif['EXIF:ExposureTime'] ?? 0), (float)($meta->exposure->exposureTimeSec ?? 0), Normalizer::DELTA, "$file: ExposureTime");
        $this->assertSame((int)($exif['EXIF:ISOSpeedRatings'] ?? $exif['EXIF:ISO'] ?? 0), (int)($meta->exposure->iso ?? 0), "$file: ISO");

        // Program, metering and white balance
        if (isset($exif['EXIF:ExposureProgram'])) {
            $ok = $this->norm->compareEnum('ExposureProgram', (int)$exif['EXIF:ExposureProgram'], $meta->exposure->program ?? null);
            $this->assertTrue($ok, "$file: ExposureProgram enum");
        }
        if (isset($exif['EXIF:MeteringMode'])) {
            $ok = $this->norm->compareEnum('MeteringMode', (int)$exif['EXIF:MeteringMode'], $meta->exposure->meteringMode ?? null);
            $this->assertTrue($ok, "$file: MeteringMode enum");
        }
        if (isset($exif['EXIF:WhiteBalance'])) {
            $ok = $this->norm->compareEnum('WhiteBalance', (int)$exif['EXIF:WhiteBalance'], $meta->exposure->whiteBalance ?? $meta->whiteBalanceDetails->mode ?? null);
            $this->assertTrue($ok, "$file: WhiteBalance enum");
        }

        // Exposure mode
        if (isset($exif['EXIF:ExposureMode'])) {
            $ok = $this->norm->compareEnum('ExposureMode', (int)$exif['EXIF:ExposureMode'], $meta->exposure->exposureMode ?? null);
            $this->assertTrue($ok, "$file: ExposureMode enum");
        }

        // Lens information
        if (isset($exif['EXIF:FocalLength'])) {
            $this->assertEqualsWithDelta((float)$exif['EXIF:FocalLength'], (float)($meta->lens->focalLengthMm ?? $meta->image->focalLengthMm ?? 0.0), Normalizer::DELTA, "$file: FocalLength");
        }
        if (isset($exif['EXIF:FocalLengthIn35mmFormat'])) {
            $this->assertSame((int)$exif['EXIF:FocalLengthIn35mmFormat'], (int)($meta->lens->focalLengthIn35mm ?? $meta->derived->focalLength35mm ?? 0), "$file: FocalLength35mm");
        }
        if (isset($exif['EXIF:LensModel'])) {
            $this->assertSame($exif['EXIF:LensModel'], $meta->lens->lensModel ?? null, "$file: LensModel");
        }

        // Temporal metadata (ISO-8601)
        $createIso = Normalizer::buildIso8601FromExif($exif, 'EXIF:CreateDate');
        $origIso   = Normalizer::buildIso8601FromExif($exif, 'EXIF:DateTimeOriginal');
        $modifyIso = Normalizer::buildIso8601FromExif($exif, 'IFD0:ModifyDate');

        if ($createIso !== null) {
            $this->assertSame($createIso, safeIso($meta->temporal->create ?? null), "$file: CreateDate");
        }
        if ($origIso !== null) {
            $this->assertSame($origIso, safeIso($meta->temporal->original ?? null), "$file: DateTimeOriginal");
        }
        if ($modifyIso !== null) {
            $this->assertSame($modifyIso, safeIso($meta->temporal->modify ?? null), "$file: ModifyDate");
        }

        // GPS
        if (isset($exif['GPS:GPSLatitude'], $exif['GPS:GPSLongitude'])) {
            $this->assertEqualsWithDelta((float)$exif['GPS:GPSLatitude'],  (float)($meta->gps->latitude  ?? 0), Normalizer::DELTA, "$file: GPS lat");
            $this->assertEqualsWithDelta((float)$exif['GPS:GPSLongitude'], (float)($meta->gps->longitude ?? 0), Normalizer::DELTA, "$file: GPS lon");
        }
        if (isset($exif['GPS:GPSAltitude'])) {
            $this->assertEqualsWithDelta((float)$exif['GPS:GPSAltitude'], (float)($meta->gps->altitude ?? 0), 1e-3, "$file: GPS alt");
        }
        if (isset($exif['GPS:GPSImgDirection'])) {
            $this->assertEqualsWithDelta((float)$exif['GPS:GPSImgDirection'], (float)($meta->gps->imageDirection ?? 0), 1e-6, "$file: GPS direction");
        }

        // ICC profile
        if (isset($exif['ICC_Profile:ProfileDescription'])) {
            $this->assertSame($exif['ICC_Profile:ProfileDescription'], $meta->colorProfile->profileName ?? null, "$file: ICC name");
        }
        if (isset($exif['ICC_Profile:ProfileVersion'])) {
            $this->assertStringStartsWith((string)$exif['ICC_Profile:ProfileVersion'], (string)($meta->colorProfile->profileVersion ?? ''), "$file: ICC version");
        }
        if (isset($exif['ICC_Profile:RenderingIntent'])) {
            $this->assertSame($exif['ICC_Profile:RenderingIntent'], $meta->colorProfile->renderingIntent ?? null, "$file: ICC intent");
        }
        if (isset($exif['ICC_Profile:ProfileID'])) {
            $this->assertSame(strtoupper((string)$exif['ICC_Profile:ProfileID']), strtoupper((string)($meta->colorProfile->profileId ?? '')), "$file: ICC id");
        }

        // Flash bitmask decoded into structured fields
        if (isset($exif['EXIF:Flash']) && isset($meta->exposure->flash)) {
            $decoded = Normalizer::decodeExifFlash((int)$exif['EXIF:Flash']);
            $this->assertSame($decoded['fired'], (bool)$meta->exposure->flash->fired, "$file: Flash fired");
            $this->assertSame($decoded['returnDetection'], enumName($meta->exposure->flash->returnDetection ?? null), "$file: Flash returnDetection");
            $this->assertSame($decoded['mode'],             enumName($meta->exposure->flash->mode ?? null),             "$file: Flash mode");
            $this->assertSame($decoded['functionPresence'],  enumName($meta->exposure->flash->functionPresence ?? null), "$file: Flash functionPresence");
            $this->assertSame($decoded['redEyeReduction'],  (bool)$meta->exposure->flash->redEyeReduction,              "$file: Flash redEyeReduction");
        }
    }

    #[Test]
    #[DataProvider('provideFiles')]
    public function test_mwg_regions_match_when_present(string $file): void
    {
        $exif = $this->loadExifToolJson($file);
        if (!array_key_exists('XMP-mwg-rs:RegionList[1]/Type', $exif) &&
            !array_key_exists('XMP-mwg-rs:Regions', $exif)) {
            $this->markTestSkipped('No MWG regions in ' . $file);
        }

        $meta = (new MetadataReader())
            ->read(self::IMAGES . '/' . $file)
            ->structured();

        $facesExif = Normalizer::mwgFaces($exif);
        $facesMeta = [];
        foreach (($meta->regions->items ?? []) as $r) {
            if (enumName($r->type ?? null) === 'FACE') {
                $facesMeta[] = ['x' => (float)$r->x, 'y' => (float)$r->y, 'w' => (float)$r->w, 'h' => (float)$r->h];
            }
        }

        $this->assertSame(count($facesExif), count($facesMeta), "$file: face count");
        foreach (array_map(null, $facesExif, $facesMeta) as [$e, $m]) {
            $this->assertEqualsWithDelta($e['x'], $m['x'], 5e-3, "$file: face x");
            $this->assertEqualsWithDelta($e['y'], $m['y'], 5e-3, "$file: face y");
            $this->assertEqualsWithDelta($e['w'], $m['w'], 5e-3, "$file: face w");
            $this->assertEqualsWithDelta($e['h'], $m['h'], 5e-3, "$file: face h");
        }
    }

    /**
     * Provide the list of fixture files for comparison.
     *
     * @return iterable<string, array{0:string}>
     */
    public static function provideFiles(): iterable
    {
        $list = glob(self::IMAGES . '/*');
        sort($list);
        foreach ($list as $p) {
            if (is_file($p)) {
                yield basename($p) => [basename($p)];
            }
        }
    }

    /**
     * Load the ExifTool JSON truth data for the given fixture.
     *
     * @return array<string,mixed>
     */
    private function loadExifToolJson(string $file): array
    {
        $json = file_get_contents(self::FIXTURES . '/' . $file . '.exiftool.json');
        \assert($json !== false, 'Missing truth: ' . $file);
        $arr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return $arr[0] ?? $arr;
    }
}
