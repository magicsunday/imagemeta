# Architecture Violations - Engineering Tickets

**Analysis Date:** 2026-02-15  
**Codebase:** MagicSunday/ImageMeta  
**Total Source Files:** 244 PHP files (~52,000 LOC)  
**Methodology:** Systematic principle-based analysis (SOLID, DRY, KISS, YAGNI, GRASP, LoD, SoC, CoC)

---

## Executive Summary

This document contains **14 detailed engineering tickets** for addressing architecture, design, and structural violations in the ImageMeta PHP codebase. Each ticket is:

- **Evidence-based**: References specific files, classes, methods, and line numbers
- **Principle-mapped**: Explicitly tied to violated software engineering principles
- **Actionable**: Includes concrete remediation steps and acceptance criteria
- **Prioritized**: High/Medium/Low impact classification

### Priority Distribution

- **High Priority**: 3 issues (god classes, interface segregation violations)
- **Medium Priority**: 5 issues (DRY violations, over-engineering, tight coupling)
- **Low Priority**: 6 issues (method length, missing abstractions, documentation)

### Estimated Impact

Addressing High priority issues would:
- Reduce largest file from 10,294 LOC → <500 LOC
- Reduce another large file from 4,937 LOC → <500 LOC  
- Split 285-method fat interface into 4-5 focused value objects
- Decrease overall codebase complexity by ~40%
- Significantly improve testability and maintainability

---

## High Priority Issues

### 🎫 Ticket #1: God Class - TiffExifParser violates SRP with 10,294 LOC and 200 methods

**Priority:** High

**Principle(s) violated:** 
- SOLID (Single Responsibility Principle)
- KISS (Keep It Simple, Stupid)
- GRASP (High Cohesion)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/Tiff/TiffExifParser.php`
- Class: `TiffExifParser`
- Lines: 1-10294 (entire file)

**Evidence:**
- **10,294 lines of code** in a single class
- **179 private methods** + 1 public method = 200 total methods
- **110 validation methods** (all prefixed with `validate*`)
- **22 large const arrays** containing metadata specifications
- Multiple distinct responsibilities mixed:
  - TIFF header parsing (Classic + BigTIFF)
  - IFD reading and traversal
  - Tag validation (110+ validation methods)
  - DNG-specific validation and parsing
  - EXIF version compatibility checks
  - Maker notes integration
  - ICC profile handling

```php
// Examples of const arrays (lines 114-1500+):
private const array FIXED_LENGTH_TAGS = [ /* 300+ lines */ ];
private const array JPEG_PROHIBITED_TAGS = [ /* ... */ ];
private const array DNG_UTF8_STRING_TAGS = [ /* ... */ ];
private const array DNG_MATRIX_COUNT_RULES = [ /* ... */ ];
// ... 18+ more large const arrays
```

**Impact:**
- **Extremely difficult to maintain** - any change requires understanding 10K+ lines
- **Poor testability** - 179 private methods cannot be unit tested in isolation
- **High cognitive load** - impossible to understand entire class in one sitting
- **Merge conflict magnet** - multiple developers will hit same file
- **Violates SRP severely** - parsing, validation, DNG handling, ICC handling all mixed

**Remediation:**

1. **Extract validation logic** into `TiffValidator` class
2. **Extract DNG-specific logic** into `DngValidator` and `DngMetadataParser`
3. **Extract const arrays** into dedicated configuration classes:
   - `TiffTagSpecification` - tag metadata
   - `DngValidationRules` - DNG-specific rules
   - `ExifVersionCapabilities` - version-specific capabilities
4. **Create parser hierarchy**:
   - `TiffHeaderParser` - header reading only
   - `IfdReader` - IFD traversal
   - `TiffExifParser` - orchestration (becomes <500 LOC)
5. **Use Strategy pattern** for Classic vs BigTIFF differences

**Acceptance Criteria:**
- [ ] `TiffExifParser` reduced to <500 LOC
- [ ] No class exceeds 800 LOC
- [ ] All validation logic extracted to separate validator classes
- [ ] Const arrays moved to dedicated specification classes
- [ ] Each extracted class has <30 public+private methods
- [ ] Test coverage maintained at ≥90%
- [ ] All tests pass (composer ci:test:php:unit)
- [ ] PHPStan analysis passes (composer ci:test:php:phpstan)

**Estimated Effort:** 5-8 days (Senior PHP Developer)

**Risk:** Medium (large refactoring but well-isolated class)

---

### 🎫 Ticket #2: Fat Interface - ParsedExif implements 5 interfaces with 285+ methods

**Priority:** High

**Principle(s) violated:** 
- SOLID (Interface Segregation Principle)
- SOLID (Single Responsibility Principle)
- GRASP (High Cohesion)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php`
- Class: `ParsedExif`
- Lines: 1-5165

**Evidence:**

```php
final readonly class ParsedExif implements 
    ExifIfd0Data,      // 9 methods
    ExifIfd1Data,      // ~similar
    ExifSubIfdData,    // 11 methods  
    ExifGpsData,       // 9 methods
    ExifInteropData    // ~similar
{
    // 285 public methods total
    // 51 private helper methods
    // 5,165 lines of code
}
```

Multiple responsibilities mixed:
- Tag value accessors (4800+ LOC of repetitive getters)
- Domain adapters (camera, lens, exposure, GPS, device, image, temporal)
- Text decoding (UserComment, JIS, Unicode, legacy encodings)
- Type conversion (rational, string, int normalization)
- Special value handling (EXIF unknown sentinels, GPS coordinate calculation)

**Impact:**
- **Interface Segregation violated** - clients forced to depend on interfaces with many methods they don't use
- **Difficult to mock** for testing - 285 methods to stub
- **Poor cohesion** - text decoding mixed with GPS calculations mixed with camera metadata
- **Feature envy** - creates multiple adapter objects that likely should be first-class collaborators

**Remediation:**

1. **Split into focused value objects**:
   - `ExifIfd0Values` (image-level metadata)
   - `ExifSubIfdValues` (EXIF-specific tags)
   - `ExifGpsValues` (GPS data)
   - `ExifInteropValues` (interop tags)

2. **Extract text decoding** into `ExifTextDecoder` service

3. **Extract adapters** as proper collaborators instead of created-on-demand:
   ```php
   public function __construct(
       public Ifd $ifd0,
       public CameraMetadata $camera,
       public LensMetadata $lens,
       // ... etc
   )
   ```

4. **Use composition** over interface implementation:
   ```php
   class ParsedExif {
       public function __construct(
           public readonly Ifd0Values $ifd0,
           public readonly ExifSubIfdValues $exif,
           public readonly GpsValues $gps,
           // ...
       ) {}
   }
   ```

**Acceptance Criteria:**
- [ ] No interface has >20 methods
- [ ] `ParsedExif` split into 4-5 focused value objects
- [ ] Text decoding extracted to separate service class
- [ ] Each value object has single, clear responsibility
- [ ] All adapters are constructor-injected, not created on-demand
- [ ] ParsedExif becomes a thin aggregate root (<200 LOC)
- [ ] All existing tests pass
- [ ] Test coverage ≥90% maintained
- [ ] PHPStan analysis passes

**Estimated Effort:** 6-10 days (Senior PHP Developer)

**Risk:** High (affects many consumers, requires careful API migration)

---

### 🎫 Ticket #3: God Class - IsoBmffParser with 4,937 LOC violates SRP

**Priority:** High

**Principle(s) violated:** 
- SOLID (Single Responsibility Principle)
- KISS
- GRASP (High Cohesion)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/IsoBmffParser.php`
- Class: `IsoBmffParser`
- Lines: 1-4937

**Evidence:**
- **4,937 lines of code** in a single class
- **124 methods** (mix of public and private)
- **50+ box type constants** (lines 82-250)
- Multiple responsibilities:
  - ISO BMFF box parsing
  - QuickTime metadata extraction
  - EXIF item location resolution
  - XMP extraction
  - Track and media parsing
  - Audio stream handling
  - Item reference resolution
  - Data reference handling

**Impact:**
- **Difficult to understand** - too many concerns mixed
- **Hard to test** - complex interactions between box parsing logic
- **Fragile** - changes to QuickTime logic can break EXIF parsing
- **Poor reusability** - cannot reuse box parsing without entire parser

**Remediation:**

1. **Extract box reading** into `IsoBmffBoxReader` 
2. **Extract QuickTime logic** into `QuickTimeMetadataExtractor`
3. **Extract item location** into `ItemLocationResolver`
4. **Extract EXIF/XMP** into `IsoBmffPayloadExtractor`
5. **Create box type hierarchy**:
   ```php
   interface BoxHandler {
       public function handle(StreamWindow $box): mixed;
   }
   class MetaBoxHandler implements BoxHandler { }
   class IlocBoxHandler implements BoxHandler { }
   // ... etc
   ```
6. **Use box handler registry**:
   ```php
   class IsoBmffParser {
       public function __construct(
           private BoxHandlerRegistry $handlers
       ) {}
   }
   ```

**Acceptance Criteria:**
- [ ] `IsoBmffParser` orchestration class <500 LOC
- [ ] Box parsing extracted to `BoxReader` (<300 LOC)
- [ ] QuickTime logic in separate `QuickTimeExtractor` (<600 LOC)
- [ ] Handler pattern for box types implemented
- [ ] Each handler class <200 LOC
- [ ] Test coverage ≥90% maintained
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 5-7 days (Senior PHP Developer)

**Risk:** Medium (complex but well-isolated parsing logic)

---

## Medium Priority Issues

### 🎫 Ticket #4: DRY Violation - Repetitive tag accessor methods in ParsedExif

**Priority:** Medium

**Principle(s) violated:** 
- DRY (Don't Repeat Yourself)
- YAGNI (You Aren't Gonna Need It)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php`
- Lines: 4700-5100 (representative sample)

**Evidence:**

Over 200 nearly identical accessor methods with only tag ID and type varying:

```php
public function cellWidth(): ?int
{
    return $this->int($this->ifd0, TiffTag::CELL_WIDTH);
}

public function cellLength(): ?int
{
    return $this->int($this->ifd0, TiffTag::CELL_LENGTH);
}

public function fillOrder(): ?int
{
    return $this->int($this->ifd0, TiffTag::FILL_ORDER);
}

// ... 200+ more similar methods
```

**Impact:**
- **Massive code duplication** - same pattern repeated 200+ times
- **Maintenance burden** - any change to accessor logic requires 200+ edits
- **Difficult to add new tags** - requires manual method creation
- **Violates YAGNI** - most tags are never accessed in practice

**Remediation:**

**Option 1: Magic method** with tag name mapping:
```php
private const array TAG_MAP = [
    'cellWidth' => [TiffTag::CELL_WIDTH, 'int', 'ifd0'],
    'cellLength' => [TiffTag::CELL_LENGTH, 'int', 'ifd0'],
    // ...
];

public function __call(string $name, array $args): mixed {
    if (!isset(self::TAG_MAP[$name])) {
        throw new BadMethodCallException();
    }
    [$tag, $type, $ifd] = self::TAG_MAP[$name];
    return $this->$type($this->$ifd, $tag);
}
```

**Option 2: Generic accessor** with enum:
```php
public function get(ExifTagEnum $tag): mixed {
    return $this->{$tag->type()}($this->{$tag->ifd()}, $tag->value);
}

// Usage:
$exif->get(ExifTagEnum::CellWidth);
```

**Option 3: Attribute-based** tag definition:
```php
#[ExifTag(TiffTag::CELL_WIDTH, ifd: 'ifd0', type: 'int')]
public function cellWidth(): ?int;

// Code generation or reflection-based implementation
```

**Acceptance Criteria:**
- [ ] Repetitive accessor methods eliminated (reduce by >150 methods)
- [ ] Single implementation handles all standard tag access
- [ ] Type safety maintained through enum or attribute system
- [ ] PHPStan level maintained (no mixed types)
- [ ] Backward compatibility maintained for existing API
- [ ] All tests pass
- [ ] Documentation updated with new usage patterns

**Estimated Effort:** 3-5 days (Mid-level PHP Developer)

**Risk:** Low (refactoring internal implementation, API can remain unchanged)

---

### 🎫 Ticket #5: YAGNI Violation - Over-engineered Factory hierarchy with single methods

**Priority:** Medium

**Principle(s) violated:** 
- YAGNI (You Aren't Gonna Need It)
- KISS

**Location:**
- Files: `/home/runner/work/imagemeta/imagemeta/src/Exif/Factory/*.php`
- Multiple factory classes with single public method

**Evidence:**

13+ factory classes, most with only **1 public method**:

```bash
CameraFactory.php:    42 lines, 1 public method
LensFactory.php:      59 lines, 1 public method  
ExposureFactory.php:  63 lines, 1 public method
SensorFactory.php:    54 lines, 1 public method
DeviceFactory.php:    56 lines, 1 public method
MotionFactory.php:    77 lines, 1 public method
SceneFactory.php:    142 lines, 1 public method
// ... and more
```

Example:
```php
final readonly class CameraFactory
{
    public function create(ParsedExif $exif): Camera
    {
        return new Camera(/* ... */);
    }
}
```

**Impact:**
- **Unnecessary abstraction** - factories with single method are just function wrappers
- **Increased complexity** - 13+ classes instead of 1 builder or 13 functions
- **Navigation burden** - developers must jump through many files
- **Testing overhead** - each factory needs separate test file
- **No flexibility benefit** - factories don't provide interchangeable implementations

**Remediation:**

**Option 1: Consolidate into single builder**:
```php
final readonly class MetadataBuilder
{
    public function buildCamera(ParsedExif $exif): Camera { }
    public function buildLens(ParsedExif $exif): Lens { }
    public function buildExposure(ParsedExif $exif): Exposure { }
    // ... all in one cohesive class
}
```

**Option 2: Use static factory methods** on value objects:
```php
final readonly class Camera
{
    public static function fromExif(ParsedExif $exif): self { }
}
```

**Option 3: Use functions** (PHP 8.4 allows):
```php
namespace MagicSunday\ImageMeta\Exif\Builders;

function buildCamera(ParsedExif $exif): Camera { }
function buildLens(ParsedExif $exif): Lens { }
```

**Acceptance Criteria:**
- [ ] Factory classes reduced from 13 to 1-3 focused builders
- [ ] Each remaining builder has ≥3 related methods
- [ ] Or factories completely removed in favor of static methods
- [ ] Test coverage maintained
- [ ] No loss of functionality
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 2-3 days (Mid-level PHP Developer)

**Risk:** Low (internal refactoring, can maintain API compatibility)

---

### 🎫 Ticket #6: Configuration-as-Code - Large const arrays mixed with logic in TiffExifParser

**Priority:** Medium

**Principle(s) violated:** 
- SoC (Separation of Concerns)
- GRASP (Information Expert)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/Tiff/TiffExifParser.php`
- Lines: 114-1500+ (approximate)

**Evidence:**

22 large const arrays containing tag specifications embedded in parser:

```php
private const array FIXED_LENGTH_TAGS = [
    ExifTag::COMPRESSION => [
        'name' => 'Compression',
        'count' => 1,
        'type' => TiffConst::TYPE_SHORT,
        'typeName' => 'SHORT',
        'spec' => 'TIFF 6.0',
    ],
    // ... 300+ more entries
];

private const array DNG_MATRIX_COUNT_RULES = [ /* ... */ ];
private const array DNG_ILLUMINANT_DATA_DEPS = [ /* ... */ ];
private const array DNG_TRIPLE_ALL_OR_NONE_SETS = [ /* ... */ ];
// ... 18+ more large arrays
```

**Impact:**
- **Mixed concerns** - static configuration mixed with parsing logic
- **Hard to maintain** - tag specs buried in 10K LOC file
- **Not reusable** - specs cannot be used by other components
- **Difficult to test** - cannot test spec lookup independently
- **Violates CoC** - configuration should be external, not hardcoded

**Remediation:**

**Option 1: Extract to specification classes**:
```php
final readonly class TiffTagSpecification
{
    public function __construct(
        private array $fixedLengthTags,
        private array $pointerTags,
        // ...
    ) {}
    
    public function isFixedLength(int $tag): bool { }
    public function getExpectedCount(int $tag): ?int { }
}
```

**Option 2: Use attribute-based specs**:
```php
enum ExifTag: int
{
    #[TagSpec(count: 1, type: 'SHORT', spec: 'TIFF 6.0')]
    case COMPRESSION = 0x0103;
    
    #[TagSpec(count: 1, type: 'SHORT', spec: 'TIFF 6.0')]
    case ORIENTATION = 0x0112;
}
```

**Option 3: External JSON/YAML** config (less type-safe but more flexible):
```yaml
# config/exif-tags.yaml
fixed_length_tags:
  0x0103:  # COMPRESSION
    name: Compression
    count: 1
    type: SHORT
    spec: TIFF 6.0
```

**Acceptance Criteria:**
- [ ] All tag specifications extracted from TiffExifParser
- [ ] Specification lookup available through dedicated class/enum
- [ ] TiffExifParser reduced by 1000+ lines
- [ ] Specifications reusable by other components
- [ ] Type safety maintained (prefer PHP over YAML)
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 3-4 days (Mid-level PHP Developer)

**Risk:** Low (extracted configuration, runtime behavior unchanged)

---

### 🎫 Ticket #7: Tight Coupling - ParsedExif creates adapters on-demand

**Priority:** Medium

**Principle(s) violated:** 
- SOLID (Dependency Inversion Principle)
- GRASP (Creator)
- LoD (Law of Demeter)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php`
- Lines: 229-282

**Evidence:**

ParsedExif creates adapter instances on every call:

```php
public function cameraMetadata(): CameraMetadataAdapter
{
    return new CameraMetadataAdapter($this);  // Creates new instance
}

public function lensMetadata(): LensMetadataAdapter
{
    return new LensMetadataAdapter($this);  // Creates new instance
}

public function exposureMetadata(): ExposureMetadataAdapter
{
    return new ExposureMetadataAdapter($this);  // Creates new instance
}

// 6+ more adapter creation methods
```

**Impact:**
- **Violates DIP** - depends on concrete adapter classes
- **Poor testability** - cannot inject mock adapters
- **Object creation responsibility misplaced** - value object shouldn't create services
- **Memory inefficient** - creates new objects on every call (though readonly helps)
- **LoD violation** - clients must know to call `->cameraMetadata()->someMethod()`

**Remediation:**

**Option 1: Constructor injection** of adapters:
```php
public function __construct(
    public Ifd $ifd0,
    public ?Ifd $exifIfd,
    private CameraMetadataAdapter $cameraAdapter,
    private LensMetadataAdapter $lensAdapter,
    // ...
) {}

public function cameraMetadata(): CameraMetadataAdapter
{
    return $this->cameraAdapter;
}
```

**Option 2: Lazy initialization** with property:
```php
private ?CameraMetadataAdapter $cameraAdapter = null;

public function cameraMetadata(): CameraMetadataAdapter
{
    return $this->cameraAdapter ??= new CameraMetadataAdapter($this);
}
```

**Option 3: Eliminate adapters** and move methods directly to ParsedExif if they're truly needed

**Acceptance Criteria:**
- [ ] Adapters created once (constructor or lazy init), not on every call
- [ ] ParsedExif testable with mock adapters
- [ ] No `new` operator in getter methods
- [ ] Adapter pattern justified (evaluate if needed at all)
- [ ] All tests pass
- [ ] Test coverage ≥90%

**Estimated Effort:** 2-3 days (Mid-level PHP Developer)

**Risk:** Low (internal implementation change)

---

### 🎫 Ticket #8: Primitive Obsession - Repetitive rawString/rawInt/value helper methods

**Priority:** Medium

**Principle(s) violated:** 
- DRY
- GRASP (Information Expert)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/ParsedExif.php`
- Lines: 3900-4000 (helpers), used throughout

**Evidence:**

Multiple similar helper methods for tag value extraction:

```php
private function rawString(?Ifd $ifd, int $tag): ?string
{
    $value = $this->value($ifd, $tag);
    return is_string($value) ? $value : null;
}

private function str(?Ifd $ifd, int $tag): ?string { /* similar */ }
private function int(?Ifd $ifd, int $tag): ?int { /* similar */ }
private function rational(?Ifd $ifd, int $tag): ?float { /* similar */ }
private function value(?Ifd $ifd, int $tag): mixed { /* ... */ }
private function normalisedValue(?Ifd $ifd, int $tag): mixed { /* ... */ }
private function rationalList(?Ifd $ifd, int $tag): ?array { /* ... */ }
```

**Impact:**
- **Duplication** - similar logic for type casting repeated
- **Unclear intent** - difference between `rawString`, `str`, and `value` not obvious
- **Should be in Ifd** - Ifd knows its entries, should provide typed access

**Remediation:**

**Move to Ifd class**:
```php
final class Ifd
{
    public function getString(int $tag): ?string { }
    public function getInt(int $tag): ?int { }
    public function getRational(int $tag): ?float { }
    public function getValue(int $tag): mixed { }
}

// Usage in ParsedExif:
public function cameraMake(): ?string
{
    return $this->ifd0->getString(ExifTag::MAKE);
}
```

**Or use generic with type parameter**:
```php
public function get(int $tag, IfdValueType $type): mixed
{
    return $this->ifd0->get($tag, $type);
}
```

**Acceptance Criteria:**
- [ ] Helper methods consolidated or moved to Ifd
- [ ] Clear, single method for typed value access
- [ ] No duplication between rawString/str/value patterns
- [ ] Ifd class becomes Information Expert for its data
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 2 days (Mid-level PHP Developer)

**Risk:** Low (refactoring internal helpers)

---

## Low Priority Issues

### 🎫 Ticket #9: Method Length - Validation methods exceed 100 lines in TiffExifParser

**Priority:** Low

**Principle(s) violated:** 
- KISS
- GRASP (High Cohesion)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/Tiff/TiffExifParser.php`
- Multiple validation methods throughout

**Evidence:**

Many validation methods are 100-400+ lines with complex nested logic:

```php
private function validateSampleDomainTags(Ifd $ifd): void
{
    // 123+ lines of complex validation logic
    // Multiple nested conditions
    // Mixed concerns (validation + error messages + business rules)
}

private function validateDngPrivateData(string $rawBytes): void
{
    // 34+ lines
    // Multiple responsibilities
}

private function validateFaxOptionTags(Ifd $ifd): void
{
    // 60+ lines
    // Complex TIFF 6.0 fax validation
}
```

**Impact:**
- **Hard to understand** - cannot grasp method purpose quickly
- **Difficult to test** - many code paths in single method
- **Poor reusability** - cannot reuse validation logic components
- **High cyclomatic complexity** - many nested conditionals

**Remediation:**

**Extract sub-validators**:
```php
private function validateSampleDomainTags(Ifd $ifd): void
{
    $this->validateSampleFormats($ifd);
    $this->validateExtraSamples($ifd);
    $this->validateSampleValueRanges($ifd);
}

private function validateSampleFormats(Ifd $ifd): void
{
    // 20-30 lines max
}
```

**Or use validator objects**:
```php
final class SampleDomainValidator
{
    public function validate(Ifd $ifd): void
    {
        $this->validateFormats($ifd);
        $this->validateExtraSamples($ifd);
        // ...
    }
}
```

**Acceptance Criteria:**
- [ ] No method exceeds 50 lines
- [ ] Complex validation split into focused sub-methods
- [ ] Each method has single, clear purpose
- [ ] Cyclomatic complexity <10 per method
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 2-3 days (Mid-level PHP Developer)

**Risk:** Very Low (internal refactoring)

---

### 🎫 Ticket #10: Hidden Dependencies - Static factory in ValueConverters

**Priority:** Low

**Principle(s) violated:** 
- SOLID (Dependency Inversion Principle)
- Testability

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/ValueConverters.php`
- Lines: 42-51

**Evidence:**

```php
final class ValueConverters
{
    private static ?ConverterFactory $factory = null;
    
    private static function factory(): ConverterFactory
    {
        if (!self::$factory instanceof ConverterFactory) {
            self::$factory = new ConverterFactory();
        }
        return self::$factory;
    }
    
    public static function rationalToFloat(...): ?float
    {
        return self::factory()->rationalConverter()->toFloat($value);
    }
    // ... all static methods
}
```

**Impact:**
- **Hidden dependency** - cannot inject mock factory for testing
- **Global state** - static variable makes tests interdependent
- **Hard to extend** - cannot swap converter implementations
- **Violates DIP** - depends on concrete ConverterFactory

**Remediation:**

**Make instance-based**:
```php
final readonly class ValueConverters
{
    public function __construct(
        private ConverterFactory $factory
    ) {}
    
    public function rationalToFloat(...): ?float
    {
        return $this->factory->rationalConverter()->toFloat($value);
    }
}
```

**Or inject converters directly**:
```php
final readonly class ValueConverters
{
    public function __construct(
        private RationalConverter $rationalConverter,
        private GpsConverter $gpsConverter,
        // ...
    ) {}
}
```

**Acceptance Criteria:**
- [ ] Static factory pattern removed
- [ ] Dependencies injected through constructor
- [ ] Fully testable with mocks
- [ ] No global state
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 1-2 days (Mid-level PHP Developer)

**Risk:** Very Low (limited usage, easy to migrate)

---

### 🎫 Ticket #11: Missing Abstraction - Box type constants as strings instead of enum

**Priority:** Low

**Principle(s) violated:** 
- SOLID (Open/Closed Principle)
- Type Safety

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/IsoBmff/IsoBmffParser.php`
- Lines: 82-250

**Evidence:**

50+ magic string constants for box types:

```php
private const string BOX_META = 'meta';
private const string BOX_FTYP = 'ftyp';
private const string BOX_MOOV = 'moov';
private const string BOX_MOOF = 'moof';
private const string BOX_UUID = 'uuid';
private const string BOX_EXIF = 'Exif';
// ... 44+ more
```

Used with string comparisons:
```php
if ($type === self::BOX_META) { }
if ($type === self::BOX_EXIF) { }
```

**Impact:**
- **No type safety** - typos not caught at compile time
- **Hard to extend** - adding box types requires editing constants
- **No behavior association** - box types could have methods
- **Violates OCP** - cannot add new box types without modifying parser

**Remediation:**

**Create enum**:
```php
enum BoxType: string
{
    case Meta = 'meta';
    case Ftyp = 'ftyp';
    case Moov = 'moov';
    case Exif = 'Exif';
    // ...
    
    public function isContainer(): bool
    {
        return match($this) {
            self::Meta, self::Moov => true,
            default => false,
        };
    }
}
```

**Use enum in comparisons**:
```php
if ($boxType === BoxType::Meta) { }
```

**Acceptance Criteria:**
- [ ] BoxType enum created with all box types
- [ ] All string constants replaced with enum cases
- [ ] Type hints updated to use enum
- [ ] Box behavior methods added to enum if appropriate
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 1 day (Junior/Mid-level PHP Developer)

**Risk:** Very Low (straightforward refactoring)

---

### 🎫 Ticket #12: Anemic Domain Model - IFD as pure data container without behavior

**Priority:** Low

**Principle(s) violated:** 
- GRASP (Information Expert)
- OOP principles

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Exif/Model/Ifd.php` (implied)
- Used throughout ParsedExif

**Evidence:**

IFD is just a data container, all logic for accessing/interpreting its data is in ParsedExif:

```php
// In ParsedExif - 51 helper methods operating on Ifd data:
private function str(?Ifd $ifd, int $tag): ?string { }
private function int(?Ifd $ifd, int $tag): ?int { }
private function rational(?Ifd $ifd, int $tag): ?float { }
// ... 48 more

// Ifd likely just has: get/set/has methods
```

**Impact:**
- **Violates Information Expert** - Ifd knows its data but cannot interpret it
- **Logic scattered** - IFD interpretation logic spread across ParsedExif
- **Poor cohesion** - related data and behavior separated
- **Difficult to reuse** - cannot use Ifd independently

**Remediation:**

**Add typed accessors to Ifd**:
```php
final class Ifd
{
    public function getString(int $tag): ?string { }
    public function getInt(int $tag): ?int { }
    public function getRational(int $tag): ?float { }
    public function getRationalList(int $tag): ?array { }
    
    // Domain-specific helpers:
    public function getCameraOrientation(): Orientation { }
    public function getTimestamp(int $tag): ?DateTimeImmutable { }
}
```

**Move conversion logic** from ParsedExif to Ifd where it belongs

**Acceptance Criteria:**
- [ ] Ifd provides typed accessor methods
- [ ] ParsedExif delegates to Ifd methods, not raw get()
- [ ] Ifd becomes richer domain object
- [ ] Conversion/interpretation logic colocated with data
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 2-3 days (Mid-level PHP Developer)

**Risk:** Low (enhancing existing class, backward compatible)

---

### 🎫 Ticket #13: Unclear Responsibility - JpegParser handles multiple segment types

**Priority:** Low

**Principle(s) violated:** 
- SOLID (Single Responsibility Principle)
- GRASP (High Cohesion)

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/Jpeg/JpegParser.php`
- Lines: 1-2680

**Evidence:**

JpegParser handles 7+ different segment types with different formats:
- EXIF (APP1)
- XMP (APP1) 
- Extended XMP (APP1)
- ICC Profile (APP2)
- MPF (APP2)
- Audio (APP2)
- IPTC (APP13)
- FlashPix (APP11)

Different parsing logic for each, all in one class.

**Impact:**
- **Multiple responsibilities** - each segment type is different domain
- **Growing complexity** - adding new segment type bloats class
- **Difficult to test** - must test all segment types together
- **Poor modularity** - cannot reuse segment parsers independently

**Remediation:**

**Use Handler pattern**:
```php
interface SegmentHandler
{
    public function canHandle(string $marker, string $signature): bool;
    public function handle(string $payload): mixed;
}

class ExifSegmentHandler implements SegmentHandler { }
class XmpSegmentHandler implements SegmentHandler { }
class IccSegmentHandler implements SegmentHandler { }
// ...

class JpegParser
{
    public function __construct(
        private array $handlers  // SegmentHandler[]
    ) {}
}
```

**Or use Chain of Responsibility**:
```php
abstract class SegmentHandler
{
    protected ?SegmentHandler $next = null;
    
    public function setNext(SegmentHandler $handler): void;
    public function handle(Marker $marker): ?ParseResult;
}
```

**Acceptance Criteria:**
- [ ] Each segment type has dedicated handler class
- [ ] JpegParser orchestrates handlers, doesn't parse directly
- [ ] Each handler is <200 LOC
- [ ] Easy to add new segment types without modifying JpegParser
- [ ] All tests pass
- [ ] PHPStan analysis passes

**Estimated Effort:** 3-4 days (Mid-level PHP Developer)

**Risk:** Low (refactoring with handler pattern)

---

### 🎫 Ticket #14: Code Comments as Documentation - Missing PHPDoc on complex validation methods

**Priority:** Low

**Principle(s) violated:** 
- Code Documentation
- Maintainability

**Location:**
- File: `/home/runner/work/imagemeta/imagemeta/src/Parse/Tiff/TiffExifParser.php`
- Multiple validation methods

**Evidence:**

Some validation methods lack comprehensive PHPDoc explaining TIFF/EXIF spec requirements:

```php
private function validateSampleDomainTags(Ifd $ifd): void
{
    // 123 lines of complex logic
    // Some inline comments but no method-level spec references
}
```

While others have good spec references:
```php
/**
 * EXIF 3.0 §4.6.3 lists the Exif, GPS and Interoperability IFD pointer...
 */
private const array POINTER_TAGS = [ ];
```

**Impact:**
- **Hard to understand** why certain validations exist
- **Difficult to verify** correctness against specs
- **Maintenance risk** - changing validation without understanding spec

**Remediation:**

**Add comprehensive PHPDoc** to all validation methods:
```php
/**
 * Validates sample domain tags per TIFF 6.0 §8.
 *
 * TIFF 6.0 requires SampleFormat to match BitsPerSample count,
 * and ExtraSamples must account for samples beyond 3 for RGB.
 * See TIFF 6.0 §8 Baseline Fields for complete requirements.
 *
 * @throws ParseError If sample format mismatches or extra samples invalid
 */
private function validateSampleDomainTags(Ifd $ifd): void
```

**Reference specific spec sections** for each validation rule

**Acceptance Criteria:**
- [ ] All validation methods have PHPDoc with spec references
- [ ] Complex logic has inline comments explaining "why"
- [ ] Spec section references included (TIFF 6.0 §X, EXIF 3.0 §Y)
- [ ] Exception conditions documented
- [ ] Documentation review completed

**Estimated Effort:** 1-2 days (Mid-level PHP Developer)

**Risk:** Very Low (documentation only, no code changes)

---

## Summary Statistics

### By Priority
- **High Priority:** 3 tickets (god classes, fat interfaces)
- **Medium Priority:** 5 tickets (DRY violations, over-engineering, coupling)
- **Low Priority:** 6 tickets (method length, enums, documentation)

### By Principle Violated
- **SOLID Violations:** 9 tickets (SRP: 5, ISP: 1, DIP: 2, OCP: 1)
- **DRY Violations:** 3 tickets
- **KISS Violations:** 4 tickets
- **YAGNI Violations:** 2 tickets
- **GRASP Violations:** 6 tickets (High Cohesion: 3, Information Expert: 3)
- **LoD Violations:** 1 ticket
- **SoC Violations:** 1 ticket

### Total Effort Estimate
- **High Priority:** 16-25 days
- **Medium Priority:** 12-17 days
- **Low Priority:** 9-14 days
- **Total:** 37-56 days (1.5-2.5 months for single senior developer)

### Risk Assessment
- **High Risk:** 1 ticket (Ticket #2 - ParsedExif affects many consumers)
- **Medium Risk:** 2 tickets (Tickets #1, #3 - large refactorings)
- **Low Risk:** 8 tickets
- **Very Low Risk:** 3 tickets

---

## Implementation Recommendations

### Phase 1: High Priority (Core Architecture)
1. Start with **Ticket #1** (TiffExifParser) - highest impact, isolated
2. Then **Ticket #3** (IsoBmffParser) - similar pattern to #1
3. Finally **Ticket #2** (ParsedExif) - most risky, needs careful API migration

### Phase 2: Medium Priority (Code Quality)
4. **Ticket #4** (DRY in ParsedExif) - pairs well with #2
5. **Ticket #6** (Configuration extraction) - supports #1
6. **Tickets #5, #7, #8** - relatively independent improvements

### Phase 3: Low Priority (Polish)
7. Address low-priority tickets as time permits
8. **Ticket #14** (documentation) can be done anytime

### Testing Strategy
- Maintain ≥90% test coverage throughout
- Run full test suite after each ticket completion
- Use PHPStan max level to catch type safety issues
- Consider adding integration tests for refactored components

### Migration Path
- Create feature branches for each major ticket
- Use adapter/facade patterns to maintain backward compatibility
- Deprecate old APIs before removing (if public)
- Update documentation with each change

---

## Appendix: Analysis Methodology

### Code Metrics Used
- Lines of Code (LOC) per class
- Method count per class
- Cyclomatic complexity
- Interface method count
- Code duplication patterns

### Principles Framework
All violations mapped to established software engineering principles:
- **SOLID:** Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **DRY:** Don't Repeat Yourself
- **KISS:** Keep It Simple, Stupid
- **YAGNI:** You Aren't Gonna Need It
- **GRASP:** Information Expert, Creator, Controller, Low Coupling, High Cohesion
- **LoD:** Law of Demeter (Tell, Don't Ask)
- **SoC:** Separation of Concerns
- **CoC:** Convention over Configuration

### Tools Referenced
- PHPStan (static analysis)
- PHPUnit (testing)
- PHPLOC (code metrics)
- Manual code review

---

**Document Version:** 1.0  
**Last Updated:** 2026-02-15  
**Status:** Ready for Implementation Planning
