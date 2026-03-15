# FlashPix Property Set Parser — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse assembled FlashPix APP2 streams as OLE property sets and extract image metadata (Summary Info, Image Info, Image Contents).

**Architecture:** FlashPix streams use Microsoft OLE Structured Storage property set format. A new `FlashPixPropertySetParser` reads the binary header, enumerates property IDs, and extracts typed values. Results populate an enhanced `FlashPix` value object. The parser follows the ICC profile pattern: raw bytes → parser → structured value object.

**Tech Stack:** PHP 8.4, binary `unpack()`, OLE property set format (FPX Appendix A.2), EXIF 3.0 §4.7.3

**References:**
- `docs/FPX.pdf` — FlashPix Format Specification 1.0 (property set binary format in Appendix A.2)
- `docs/EXIF-300.pdf` — EXIF 3.0 §4.7.3 (FlashPix extensions in APP2)
- `src/Parse/Jpeg/FlashPixStreamAssembler.php` — existing assembler (input for this parser)

---

## Chunk 1: OLE Property Set Parser

### File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `src/Parse/FlashPix/OlePropertySetParser.php` | Parse OLE property set binary format (header, section, properties) |
| Create | `src/Parse/FlashPix/OlePropertyType.php` | Enum for OLE VT_* type codes |
| Create | `src/Parse/FlashPix/OlePropertySet.php` | Value object: parsed property set (array of PID → typed value) |
| Create | `tests/Parse/FlashPix/OlePropertySetParserTest.php` | Tests with synthetic binary property sets |

### Task 1: OLE Property Type Enum

**Files:**
- Create: `src/Parse/FlashPix/OlePropertyType.php`
- Test: `tests/Parse/FlashPix/OlePropertyTypeTest.php`

- [ ] **Step 1: Write test for enum values**

```php
#[Test]
public function enumExposesExpectedPropertyTypeValues(): void
{
    self::assertSame(0x0002, OlePropertyType::Short->value);
    self::assertSame(0x0003, OlePropertyType::Long->value);
    self::assertSame(0x0005, OlePropertyType::Double->value);
    self::assertSame(0x000B, OlePropertyType::Boolean->value);
    self::assertSame(0x001E, OlePropertyType::Lpstr->value);
    self::assertSame(0x001F, OlePropertyType::Lpwstr->value);
    self::assertSame(0x0040, OlePropertyType::Filetime->value);
    self::assertSame(0x0041, OlePropertyType::Blob->value);
    self::assertSame(0x0048, OlePropertyType::ClsId->value);
    self::assertSame(0x1002, OlePropertyType::VectorShort->value);
    self::assertSame(0x101E, OlePropertyType::VectorLpstr->value);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make unit` — Expected: FAIL (class not found)

- [ ] **Step 3: Implement OlePropertyType enum**

```php
enum OlePropertyType: int
{
    case Short       = 0x0002;
    case Long        = 0x0003;
    case Float       = 0x0004;
    case Double      = 0x0005;
    case Boolean     = 0x000B;
    case Lpstr       = 0x001E;
    case Lpwstr      = 0x001F;
    case Filetime    = 0x0040;
    case Blob        = 0x0041;
    case ClsId       = 0x0048;
    case VectorShort = 0x1002;
    case VectorLpstr = 0x101E;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make unit` — Expected: PASS

- [ ] **Step 5: Commit**

```
GH-2269: Add OLE property type enum for FlashPix parsing
```

---

### Task 2: OLE Property Set Value Object

**Files:**
- Create: `src/Parse/FlashPix/OlePropertySet.php`
- Test: `tests/Parse/FlashPix/OlePropertySetTest.php`

- [ ] **Step 1: Write test**

```php
#[Test]
public function constructorStoresCodepageAndProperties(): void
{
    $set = new OlePropertySet(1252, [1 => 1252, 2 => 'Test Title']);

    self::assertSame(1252, $set->codepage);
    self::assertSame('Test Title', $set->property(2));
    self::assertNull($set->property(999));
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement**

```php
final readonly class OlePropertySet
{
    /** @param array<int, mixed> $properties PID → value map */
    public function __construct(
        public int $codepage,
        private array $properties,
    ) {
    }

    public function property(int $pid): mixed
    {
        return $this->properties[$pid] ?? null;
    }

    /** @return array<int, mixed> */
    public function all(): array
    {
        return $this->properties;
    }
}
```

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Add OLE property set value object
```

---

### Task 3: OLE Property Set Parser — Header Validation

**Files:**
- Create: `src/Parse/FlashPix/OlePropertySetParser.php`
- Test: `tests/Parse/FlashPix/OlePropertySetParserTest.php`

The OLE property set header is:
- `uint16 byteOrder` (must be 0xFFFE = little-endian)
- `uint16 version` (must be 0x0000)
- `uint32 osVersion`
- `16 bytes classId`
- `uint32 sectionCount` (must be ≥ 1)
- Per section: `16 bytes formatId` + `uint32 offset`

- [ ] **Step 1: Write failing test for valid header**

```php
#[Test]
public function parseReturnsPropertySetFromValidHeader(): void
{
    $raw    = $this->buildMinimalPropertySet(1252, [2 => 'Title']);
    $parser = new OlePropertySetParser();
    $result = $parser->parse($raw);

    self::assertInstanceOf(OlePropertySet::class, $result);
    self::assertSame(1252, $result->codepage);
    self::assertSame('Title', $result->property(2));
}

#[Test]
public function parseReturnsNullForInvalidByteOrder(): void
{
    $raw    = "\x00\x00" . str_repeat("\x00", 26);
    $parser = new OlePropertySetParser();

    self::assertNull($parser->parse($raw));
}

#[Test]
public function parseReturnsNullForTruncatedInput(): void
{
    $parser = new OlePropertySetParser();

    self::assertNull($parser->parse('short'));
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement parser with header validation and section parsing**

The parser reads:
1. Header (28 bytes minimum: 2+2+4+16+4)
2. Section offset table (20 bytes per section: 16 GUID + 4 offset)
3. First section: `uint32 size` + `uint32 propertyCount`
4. Property offset table: `uint32 pid` + `uint32 offset` per property
5. Property values: `uint32 type` + type-specific data

Key method: `parse(string $raw): ?OlePropertySet`

Implementation follows the streaming parsing pattern from `SamsungDecoder` — read header, validate, iterate entries, extract typed values.

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Add OLE property set parser with header and section parsing
```

---

### Task 4: Property Value Type Decoders

**Files:**
- Modify: `src/Parse/FlashPix/OlePropertySetParser.php`
- Test: `tests/Parse/FlashPix/OlePropertySetParserTest.php`

- [ ] **Step 1: Write tests for each value type**

```php
#[Test]
public function parsesLpstrProperty(): void
{
    $raw    = $this->buildMinimalPropertySet(1252, [2 => 'ASCII Title']);
    $result = (new OlePropertySetParser())->parse($raw);

    self::assertSame('ASCII Title', $result?->property(2));
}

#[Test]
public function parsesLpwstrProperty(): void
{
    $raw    = $this->buildUnicodePropertySet(1200, [2 => 'Unicode Title']);
    $result = (new OlePropertySetParser())->parse($raw);

    self::assertSame('Unicode Title', $result?->property(2));
}

#[Test]
public function parsesLongProperty(): void
{
    $raw    = $this->buildMinimalPropertySet(1252, [3 => 42]);
    $result = (new OlePropertySetParser())->parse($raw);

    self::assertSame(42, $result?->property(3));
}
```

- [ ] **Step 2: Run tests — FAIL**

- [ ] **Step 3: Implement type decoders for LPSTR, LPWSTR, Long, Short, Double, Boolean, Filetime, Blob, Vector types**

Each decoder reads the type indicator and extracts the value:
- `VT_LPSTR`: `uint32 size` + `size` bytes ANSI string (NUL-padded to 4-byte boundary)
- `VT_LPWSTR`: `uint32 size` (char count) + `size*2` bytes UTF-16LE string
- `VT_I4/VT_I2`: `int32`/`int16` little-endian
- `VT_R8`: IEEE 754 double
- `VT_BOOL`: `int16` (0=false, -1=true)
- `VT_FILETIME`: 64-bit Windows FILETIME → DateTimeImmutable
- `VT_VECTOR|VT_*`: `uint32 count` + `count` values

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Add OLE property value type decoders
```

---

## Chunk 2: FlashPix Metadata Extraction

### File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `src/Parse/FlashPix/FlashPixPropertyExtractor.php` | Map OLE PIDs to FlashPix metadata fields |
| Modify | `src/Value/FlashPix.php` | Add structured metadata properties |
| Modify | `src/Exif/Factory/ValueFactory.php:313` | Invoke parser when creating FlashPix |
| Modify | `scripts/imagemeta-format.php:2520-2533` | Display extracted metadata |
| Create | `tests/Parse/FlashPix/FlashPixPropertyExtractorTest.php` | Tests |

### Task 5: FlashPix Property Extractor

**Files:**
- Create: `src/Parse/FlashPix/FlashPixPropertyExtractor.php`
- Test: `tests/Parse/FlashPix/FlashPixPropertyExtractorTest.php`

Maps well-known FlashPix PIDs to named fields:

**Summary Information PIDs (codepage 1252):**
- PID 2: Title
- PID 3: Subject
- PID 4: Author
- PID 5: Keywords
- PID 6: Comments
- PID 8: Last saved by
- PID 9: Revision number
- PID 12: Create time
- PID 13: Last saved time
- PID 18: Application name

**Image Info PIDs (codepage 1200):**
- Camera-specific PIDs defined in FPX §6.5-6.7

- [ ] **Step 1: Write test**

```php
#[Test]
public function extractsSummaryInfoProperties(): void
{
    $set       = new OlePropertySet(1252, [2 => 'Photo Title', 4 => 'John Doe']);
    $extractor = new FlashPixPropertyExtractor();
    $info      = $extractor->extractSummaryInfo($set);

    self::assertSame('Photo Title', $info->title);
    self::assertSame('John Doe', $info->author);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Implement extractor with Summary Info and Image Info mappings**

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Add FlashPix property extractor for Summary Info and Image Info
```

---

### Task 6: Enhance FlashPix Value Object

**Files:**
- Modify: `src/Value/FlashPix.php`
- Modify: `tests/Value/FlashPixTest.php`

- [ ] **Step 1: Write test for enhanced value object**

```php
#[Test]
public function constructorStoresStructuredProperties(): void
{
    $summary = new FlashPixSummaryInfo('Title', null, 'Author', null, null, null);
    $fpx     = new FlashPix([], $summary);

    self::assertSame('Title', $fpx->summaryInfo?->title);
    self::assertSame('Author', $fpx->summaryInfo?->author);
}
```

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Add structured properties to FlashPix**

Add optional `?FlashPixSummaryInfo $summaryInfo` and `?FlashPixImageInfo $imageInfo` constructor params alongside existing `$streams`.

- [ ] **Step 4: Run test — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Add structured metadata properties to FlashPix value object
```

---

### Task 7: Factory Integration

**Files:**
- Modify: `src/Exif/Factory/ValueFactory.php:313`
- Test: existing integration tests should still pass

- [ ] **Step 1: Write test for factory integration**

Test that `ValueFactory` invokes parser when FlashPix streams are present.

- [ ] **Step 2: Run test — FAIL**

- [ ] **Step 3: Update ValueFactory to invoke OlePropertySetParser + FlashPixPropertyExtractor**

Replace:
```php
$flashPix = new FlashPix($metadata->flashPixStreams);
```

With parsing logic that iterates streams, parses each as OLE property set, and extracts structured metadata.

- [ ] **Step 4: Run test + `make test` — PASS**

- [ ] **Step 5: Commit**

```
GH-2269: Integrate FlashPix property set parsing into ValueFactory
```

---

### Task 8: Format Script Update

**Files:**
- Modify: `scripts/imagemeta-format.php:2520-2533`

- [ ] **Step 1: Update `printFlashPixSection` to display extracted metadata**

Use `flattenObjectProperties()` on FlashPix value object (same pattern as Apple/DJI MakerNotes).

- [ ] **Step 2: Run `make test` — PASS**

- [ ] **Step 3: Commit**

```
GH-2269: Display extracted FlashPix metadata in format script
```
