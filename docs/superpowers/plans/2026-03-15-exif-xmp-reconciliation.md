# EXIF↔XMP Property Reconciliation — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Systematically fall back from EXIF to XMP (and vice versa) across all 16 mapping tables defined in CIPA DC-X010-2017, replacing the current ad-hoc fallbacks in factories with a central mapping registry.

**Architecture:** A `ExifXmpMapping` registry maps EXIF tag IDs to XMP namespace + property + value type. Each factory consults the registry via a shared `XmpFallbackResolver` that handles type normalization (Rational → float, XMP DateTime → DateTimeImmutable, XMP boolean → int). Version-aware namespace selection (`exif:` vs `exifEX:`) is handled centrally.

**Tech Stack:** PHP 8.4, CIPA DC-X010-2017, XMP Specification Part 2

**References:**
- `docs/CIPA-DC-X010-2017.pdf` — Exif metadata for XMP (16 mapping tables)
- `docs/EXIF-310.pdf` — EXIF 3.1 §H.6 (tag registry)
- `docs/XMP.pdf` — XMP Specification
- `src/Model/Xmp/XmpNamespace.php` — XMP namespace enum (needs `EXIFEX` addition)
- `src/Exif/Factory/` — existing sub-factories with ad-hoc fallbacks

---

## Chunk 1: Foundation — Namespace + Mapping Registry

### File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `src/Model/Xmp/XmpNamespace.php` | Add `EXIFEX` namespace case |
| Create | `src/Exif/Reconciliation/ExifXmpValueType.php` | Enum for mapping value types (Integer, Rational, Text, Date, etc.) |
| Create | `src/Exif/Reconciliation/ExifXmpMapping.php` | Single mapping entry: EXIF tag → XMP namespace + property + type |
| Create | `src/Exif/Reconciliation/ExifXmpMappingRegistry.php` | Central registry holding all 16 tables of mappings |
| Create | `tests/Exif/Reconciliation/ExifXmpMappingRegistryTest.php` | Tests |

### Task 1: Add EXIFEX Namespace

**Files:**
- Modify: `src/Model/Xmp/XmpNamespace.php`
- Test: `tests/Model/Xmp/XmpNamespaceTest.php` (if exists)

- [ ] **Step 1: Write test**

```php
#[Test]
public function exifExNamespaceIsDefined(): void
{
    self::assertSame('http://cipa.jp/exif/1.0/', XmpNamespace::EXIFEX->value);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Add enum case**

```php
case EXIFEX = 'http://cipa.jp/exif/1.0/';
```

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add exifEX namespace (CIPA DC-X010-2017) to XmpNamespace enum
```

---

### Task 2: ExifXmpValueType Enum

**Files:**
- Create: `src/Exif/Reconciliation/ExifXmpValueType.php`
- Test: `tests/Exif/Reconciliation/ExifXmpValueTypeTest.php`

Value types from CIPA DC-X010-2017:

- [ ] **Step 1: Write test**

```php
#[Test]
public function enumExposesExpectedValueTypes(): void
{
    self::assertSame('Integer', ExifXmpValueType::Integer->value);
    self::assertSame('Rational', ExifXmpValueType::Rational->value);
    self::assertSame('Text', ExifXmpValueType::Text->value);
    self::assertSame('Date', ExifXmpValueType::Date->value);
    self::assertSame('LanguageAlternative', ExifXmpValueType::LanguageAlternative->value);
    self::assertSame('ProperName', ExifXmpValueType::ProperName->value);
    self::assertSame('GPSCoordinate', ExifXmpValueType::GpsCoordinate->value);
    self::assertSame('OrderedArrayOfInteger', ExifXmpValueType::OrderedArrayOfInteger->value);
    self::assertSame('OrderedArrayOfRational', ExifXmpValueType::OrderedArrayOfRational->value);
    self::assertSame('ClosedChoiceOfInteger', ExifXmpValueType::ClosedChoiceOfInteger->value);
    self::assertSame('ClosedChoiceOfText', ExifXmpValueType::ClosedChoiceOfText->value);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement enum**

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add ExifXmpValueType enum for mapping type descriptors
```

---

### Task 3: ExifXmpMapping Entry

**Files:**
- Create: `src/Exif/Reconciliation/ExifXmpMapping.php`
- Test: `tests/Exif/Reconciliation/ExifXmpMappingTest.php`

- [ ] **Step 1: Write test**

```php
#[Test]
public function constructorStoresMappingFields(): void
{
    $mapping = new ExifXmpMapping(
        exifTag: 0x010F,
        xmpNamespace: XmpNamespace::TIFF,
        xmpProperty: 'Make',
        valueType: ExifXmpValueType::ProperName,
    );

    self::assertSame(0x010F, $mapping->exifTag);
    self::assertSame(XmpNamespace::TIFF, $mapping->xmpNamespace);
    self::assertSame('Make', $mapping->xmpProperty);
    self::assertSame(ExifXmpValueType::ProperName, $mapping->valueType);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement value object**

```php
final readonly class ExifXmpMapping
{
    public function __construct(
        public int $exifTag,
        public XmpNamespace $xmpNamespace,
        public string $xmpProperty,
        public ExifXmpValueType $valueType,
    ) {
    }
}
```

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add ExifXmpMapping entry value object
```

---

### Task 4: Mapping Registry — TIFF Properties (Tables 3-5)

**Files:**
- Create: `src/Exif/Reconciliation/ExifXmpMappingRegistry.php`
- Test: `tests/Exif/Reconciliation/ExifXmpMappingRegistryTest.php`

- [ ] **Step 1: Write test for TIFF table lookups**

```php
#[Test]
public function findsImageWidthMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::IMAGE_WIDTH);

    self::assertInstanceOf(ExifXmpMapping::class, $mapping);
    self::assertSame(XmpNamespace::TIFF, $mapping->xmpNamespace);
    self::assertSame('ImageWidth', $mapping->xmpProperty);
    self::assertSame(ExifXmpValueType::Integer, $mapping->valueType);
}

#[Test]
public function findsMakeMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::MAKE);

    self::assertSame(XmpNamespace::TIFF, $mapping?->xmpNamespace);
    self::assertSame('Make', $mapping?->xmpProperty);
}

#[Test]
public function findsDateTimeMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::DATE_TIME);

    self::assertSame(XmpNamespace::XAP, $mapping?->xmpNamespace);
    self::assertSame('ModifyDate', $mapping?->xmpProperty);
    self::assertSame(ExifXmpValueType::Date, $mapping?->valueType);
}

#[Test]
public function returnsNullForUnmappedTag(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();

    self::assertNull($registry->findByExifTag(0xFFFF));
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement registry with Tables 3-5**

Registry stores `array<int, ExifXmpMapping>` keyed by EXIF tag ID. Factory method `createDefault()` populates all entries from CIPA DC-X010-2017 Tables 3-5:

**Table 3 (TIFF Image Data Structure):**
- 0x0100 ImageWidth → tiff:ImageWidth (Integer)
- 0x0101 ImageLength → tiff:ImageLength (Integer)
- 0x0102 BitsPerSample → tiff:BitsPerSample (OrderedArrayOfInteger)
- 0x0103 Compression → tiff:Compression (ClosedChoiceOfInteger)
- 0x0106 PhotometricInterpretation → tiff:PhotometricInterpretation (ClosedChoiceOfInteger)
- 0x0112 Orientation → tiff:Orientation (ClosedChoiceOfInteger)
- 0x0115 SamplesPerPixel → tiff:SamplesPerPixel (Integer)
- 0x011C PlanarConfiguration → tiff:PlanarConfiguration (ClosedChoiceOfInteger)
- 0x0212 YCbCrSubSampling → tiff:YCbCrSubSampling (OrderedArrayOfInteger)
- 0x0213 YCbCrPositioning → tiff:YCbCrPositioning (ClosedChoiceOfInteger)
- 0x011A XResolution → tiff:XResolution (Rational)
- 0x011B YResolution → tiff:YResolution (Rational)
- 0x0128 ResolutionUnit → tiff:ResolutionUnit (ClosedChoiceOfInteger)

**Table 4 (TIFF Image Data Characteristics):**
- 0x012D TransferFunction → tiff:TransferFunction (OrderedArrayOfInteger)
- 0x013E WhitePoint → tiff:WhitePoint (OrderedArrayOfRational)
- 0x013F PrimaryChromaticities → tiff:PrimaryChromaticities (OrderedArrayOfRational)
- 0x0211 YCbCrCoefficients → tiff:YCbCrCoefficients (OrderedArrayOfRational)
- 0x0214 ReferenceBlackWhite → tiff:ReferenceBlackWhite (OrderedArrayOfRational)

**Table 5 (Other TIFF Properties):**
- 0x0132 DateTime → xmp:ModifyDate (Date)
- 0x010E ImageDescription → dc:description (LanguageAlternative)
- 0x010F Make → tiff:Make (ProperName)
- 0x0110 Model → tiff:Model (Text)
- 0x0131 Software → xmp:CreatorTool (Text)
- 0x013B Artist → dc:creator (OrderedArrayOfProperName — special handling)
- 0x8298 Copyright → dc:rights (LanguageAlternative)

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add ExifXmpMappingRegistry with TIFF property tables (Tables 3-5)
```

---

### Task 5: Mapping Registry — Exif Properties (Tables 7-14)

**Files:**
- Modify: `src/Exif/Reconciliation/ExifXmpMappingRegistry.php`
- Test: `tests/Exif/Reconciliation/ExifXmpMappingRegistryTest.php`

- [ ] **Step 1: Write tests for Exif table lookups**

```php
#[Test]
public function findsExifVersionMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::EXIF_VERSION);

    self::assertSame(XmpNamespace::EXIF, $mapping?->xmpNamespace);
    self::assertSame('ExifVersion', $mapping?->xmpProperty);
}

#[Test]
public function findsExposureTimeMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::EXPOSURE_TIME);

    self::assertSame(XmpNamespace::EXIF, $mapping?->xmpNamespace);
    self::assertSame('ExposureTime', $mapping?->xmpProperty);
    self::assertSame(ExifXmpValueType::Rational, $mapping?->valueType);
}

#[Test]
public function findsLensModelInExifExNamespace(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::LENS_MODEL);

    self::assertSame(XmpNamespace::EXIFEX, $mapping?->xmpNamespace);
    self::assertSame('LensModel', $mapping?->xmpProperty);
}

#[Test]
public function findsPhotographicSensitivityInExifExNamespace(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::PHOTOGRAPHIC_SENSITIVITY);

    self::assertSame(XmpNamespace::EXIFEX, $mapping?->xmpNamespace);
    self::assertSame('PhotographicSensitivity', $mapping?->xmpProperty);
}
```

- [ ] **Step 2: Run tests — FAIL**

- [ ] **Step 3: Add Tables 7-14 to registry**

**Table 7 (Version):** ExifVersion, FlashpixVersion
**Table 8 (Characteristics):** ColorSpace, Gamma (exifEX)
**Table 9 (Configuration):** ComponentsConfiguration, CompressedBitsPerPixel, PixelXDimension, PixelYDimension
**Table 10 (User):** UserComment (MakerNote excluded)
**Table 11 (File):** RelatedSoundFile
**Table 12 (DateTime):** DateTimeOriginal, DateTimeDigitized (SubSec/Offset tags are merged, not mapped individually)
**Table 13 (Picture-taking):** ExposureTime, FNumber, ExposureProgram, PhotographicSensitivity (exifEX), SensitivityType (exifEX), StandardOutputSensitivity (exifEX), RecommendedExposureIndex (exifEX), ISOSpeed (exifEX), ISOSpeedLatitudeyyy (exifEX), ISOSpeedLatitudezzz (exifEX), ShutterSpeedValue, ApertureValue, BrightnessValue, ExposureBiasValue, MaxApertureValue, SubjectDistance, MeteringMode, LightSource, Flash, FocalLength, SubjectArea, FlashEnergy, SpatialFrequencyResponse, FocalPlaneXResolution, FocalPlaneYResolution, FocalPlaneResolutionUnit, SubjectLocation, ExposureIndex, SensingMethod, FileSource, SceneType, CFAPattern, CustomRendered, ExposureMode, WhiteBalance, DigitalZoomRatio, FocalLengthIn35mmFilm, SceneCaptureType, GainControl, Contrast, Saturation, Sharpness, DeviceSettingDescription, SubjectDistanceRange
**Table 14 (Other):** ImageUniqueID, CameraOwnerName (exifEX), BodySerialNumber (exifEX), LensSpecification (exifEX), LensMake (exifEX), LensModel (exifEX), LensSerialNumber (exifEX), CompositeImage (exifEX), SourceImageNumberOfCompositeImage (exifEX), SourceExposureTimesOfCompositeImage (exifEX)

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add Exif property mapping tables (Tables 7-14) to registry
```

---

### Task 6: Mapping Registry — GPS + Interop (Tables 15-16)

**Files:**
- Modify: `src/Exif/Reconciliation/ExifXmpMappingRegistry.php`
- Test: `tests/Exif/Reconciliation/ExifXmpMappingRegistryTest.php`

- [ ] **Step 1: Write tests for GPS and interop lookups**

```php
#[Test]
public function findsGpsLatitudeMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::GPS_LATITUDE, gps: true);

    self::assertSame(XmpNamespace::EXIF, $mapping?->xmpNamespace);
    self::assertSame('GPSLatitude', $mapping?->xmpProperty);
    self::assertSame(ExifXmpValueType::GpsCoordinate, $mapping?->valueType);
}

#[Test]
public function findsInteroperabilityIndexMapping(): void
{
    $registry = ExifXmpMappingRegistry::createDefault();
    $mapping  = $registry->findByExifTag(ExifTag::INTEROPERABILITY_INDEX, interop: true);

    self::assertSame(XmpNamespace::EXIFEX, $mapping?->xmpNamespace);
    self::assertSame('InteroperabilityIndex', $mapping?->xmpProperty);
}
```

Note: GPS and Interop IFDs have their own tag ID spaces (tag 0x0001 means different things in GPS vs Interop vs main IFD). The registry must distinguish them, e.g. via `findByExifTag(int $tag, bool $gps = false, bool $interop = false)` or separate lookup methods.

- [ ] **Step 2: Run tests — FAIL**

- [ ] **Step 3: Add Tables 15-16**

**Table 15 (GPS):** All GPS tags (GPSVersionID through GPSHPositioningError). Note: GPSLatitudeRef/GPSLongitudeRef are merged into GPSLatitude/GPSLongitude in XMP.

**Table 16 (Interop):** InteroperabilityIndex → exifEX:InteroperabilityIndex

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add GPS and Interoperability mapping tables (Tables 15-16) to registry
```

---

## Chunk 2: XMP Fallback Resolver + Factory Integration

### File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `src/Exif/Reconciliation/XmpFallbackResolver.php` | Resolve XMP values using registry + type normalization |
| Modify | `src/Exif/Factory/ExposureFactory.php` | Add XMP fallbacks for picture-taking conditions |
| Modify | `src/Exif/Factory/CameraFactory.php` | Add XMP fallbacks for camera/lens owner |
| Modify | `src/Exif/Factory/LensFactory.php` | Add XMP fallbacks for lens fields |
| Modify | `src/Exif/Factory/GpsFactory.php` | Migrate ad-hoc XMP lookups to use registry |
| Modify | `src/Exif/Factory/TemporalFactory.php` | Migrate ad-hoc XMP lookups to use registry |
| Modify | `src/Exif/Factory/ImageFactory.php` | Migrate ad-hoc XMP lookups to use registry |
| Create | `tests/Exif/Reconciliation/XmpFallbackResolverTest.php` | Tests |

### Task 7: XmpFallbackResolver

**Files:**
- Create: `src/Exif/Reconciliation/XmpFallbackResolver.php`
- Test: `tests/Exif/Reconciliation/XmpFallbackResolverTest.php`

The resolver accepts a `XmpDocument` and `ExifXmpMappingRegistry`, and provides typed accessors:

- [ ] **Step 1: Write tests**

```php
#[Test]
public function resolvesIntegerFromXmp(): void
{
    $xmpDoc   = $this->buildXmpDocumentWithProperty('http://ns.adobe.com/tiff/1.0/', 'ImageWidth', '4000');
    $registry = ExifXmpMappingRegistry::createDefault();
    $resolver = new XmpFallbackResolver($xmpDoc, $registry);

    self::assertSame(4000, $resolver->int(ExifTag::IMAGE_WIDTH));
}

#[Test]
public function resolvesRationalAsFloatFromXmp(): void
{
    $xmpDoc   = $this->buildXmpDocumentWithProperty('http://ns.adobe.com/exif/1.0/', 'ExposureTime', '1/125');
    $registry = ExifXmpMappingRegistry::createDefault();
    $resolver = new XmpFallbackResolver($xmpDoc, $registry);

    self::assertEqualsWithDelta(1 / 125, $resolver->float(ExifTag::EXPOSURE_TIME), 0.0001);
}

#[Test]
public function resolvesDateTimeFromXmp(): void
{
    $xmpDoc   = $this->buildXmpDocumentWithProperty('http://ns.adobe.com/xap/1.0/', 'ModifyDate', '2025-01-15T10:30:00+01:00');
    $registry = ExifXmpMappingRegistry::createDefault();
    $resolver = new XmpFallbackResolver($xmpDoc, $registry);

    $dateTime = $resolver->dateTime(ExifTag::DATE_TIME);
    self::assertInstanceOf(\DateTimeImmutable::class, $dateTime);
    self::assertSame('2025-01-15', $dateTime->format('Y-m-d'));
}

#[Test]
public function returnsNullForMissingProperty(): void
{
    $xmpDoc   = $this->buildEmptyXmpDocument();
    $registry = ExifXmpMappingRegistry::createDefault();
    $resolver = new XmpFallbackResolver($xmpDoc, $registry);

    self::assertNull($resolver->int(ExifTag::IMAGE_WIDTH));
}
```

- [ ] **Step 2: Run tests — FAIL**

- [ ] **Step 3: Implement resolver**

Methods:
- `int(int $exifTag): ?int` — lookup + parse integer
- `float(int $exifTag): ?float` — lookup + parse rational string to float
- `string(int $exifTag): ?string` — lookup + return string
- `dateTime(int $exifTag): ?DateTimeImmutable` — lookup + parse ISO 8601
- `stringList(int $exifTag): ?array` — lookup + return ordered array

Each method:
1. Looks up the mapping in the registry
2. Queries `XmpDocument` with the mapped namespace + property
3. Converts the XMP value to the expected PHP type

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add XmpFallbackResolver with typed accessors and value normalization
```

---

### Task 8: ExposureFactory — Add XMP Fallbacks

**Files:**
- Modify: `src/Exif/Factory/ExposureFactory.php`
- Test: `tests/Exif/Factory/ExposureFactoryTest.php`

- [ ] **Step 1: Write test for XMP fallback**

```php
#[Test]
public function fallsBackToXmpForExposureTimeWhenExifMissing(): void
{
    $metadata = $this->buildMetadataWithXmpOnly([
        'http://ns.adobe.com/exif/1.0/' => ['ExposureTime' => '1/250'],
    ]);
    $factory  = new ExposureFactory(/* with resolver */);
    $exposure = $factory->create($metadata);

    self::assertEqualsWithDelta(1 / 250, $exposure->settings->exposureTimeSec, 0.0001);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Inject `XmpFallbackResolver` into ExposureFactory, apply `$exifValue ?? $resolver->float(tag)` pattern for each field**

- [ ] **Step 4: Run test + `make test` — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add XMP fallbacks to ExposureFactory for picture-taking conditions
```

---

### Task 9: CameraFactory + LensFactory — Add XMP Fallbacks

**Files:**
- Modify: `src/Exif/Factory/CameraFactory.php`
- Modify: `src/Exif/Factory/LensFactory.php`
- Test: `tests/Exif/Factory/CameraFactoryTest.php`, `tests/Exif/Factory/LensFactoryTest.php`

- [ ] **Step 1: Write tests for exifEX namespace fields**

Test that CameraOwnerName, BodySerialNumber come from `exifEX:` namespace.
Test that LensMake, LensModel, LensSerialNumber, LensSpecification come from `exifEX:` namespace.

- [ ] **Step 2: Run tests — FAIL**

- [ ] **Step 3: Inject resolver and apply fallbacks**

- [ ] **Step 4: Run test + `make test` — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Add XMP fallbacks to Camera and Lens factories (exifEX namespace)
```

---

### Task 10: Migrate GpsFactory, TemporalFactory, ImageFactory

**Files:**
- Modify: `src/Exif/Factory/GpsFactory.php`
- Modify: `src/Exif/Factory/TemporalFactory.php`
- Modify: `src/Exif/Factory/ImageFactory.php`
- Existing tests must still pass

- [ ] **Step 1: Verify existing tests pass before changes**

Run: `make unit` — all green

- [ ] **Step 2: Migrate GpsFactory to use XmpFallbackResolver instead of direct XmpDocument queries**

Replace inline `$xmpDocument?->string(XmpNamespace::EXIF->value, 'GPSLatitude')` calls with resolver pattern.

- [ ] **Step 3: Run tests — PASS (no behavior change)**

- [ ] **Step 4: Migrate TemporalFactory and ImageFactory similarly**

- [ ] **Step 5: Run `make test` — PASS**

- [ ] **Step 6: Commit**

```
GH-2267: Migrate GPS, Temporal, and Image factories to use XmpFallbackResolver
```

---

## Chunk 3: Remaining Factories + Final Validation

### Task 11: Remaining Exif Properties Factories

Cover any TIFF/Exif properties from Tables 3-14 not yet handled by existing factories:
- TIFF image structure fields (ImageWidth, ImageLength, BitsPerSample, etc.)
- TIFF characteristics (TransferFunction, WhitePoint, PrimaryChromaticities, etc.)
- Exif version/configuration fields (ExifVersion, FlashpixVersion, ColorSpace, etc.)
- User information (UserComment)
- File information (RelatedSoundFile)

- [ ] **Step 1: Audit which Table 3-14 mappings are not yet consumed by any factory**

- [ ] **Step 2: Add XMP fallbacks to the appropriate factories**

- [ ] **Step 3: Write tests for each new fallback**

- [ ] **Step 4: Run `make test` — PASS**

- [ ] **Step 5: Commit**

```
GH-2267: Complete remaining EXIF↔XMP fallbacks across all factories
```

---

### Task 12: Full CI + Cleanup

- [ ] **Step 1: Run `make test` — all green**

- [ ] **Step 2: Verify no ad-hoc XMP fallback patterns remain**

Grep for direct `$xmpDocument?->string(XmpNamespace::EXIF->value,` calls that should use the resolver instead. Some may intentionally remain (non-spec properties like Photoshop, Lightroom).

- [ ] **Step 3: Commit + close issue**

```
GH-2267: Add systematic EXIF↔XMP property reconciliation per CIPA DC-010
```
