#!/bin/bash

# Script to create all GitHub issues from the forensic audit
# Usage: ./create_issues.sh

set -e

REPO="magicsunday/imagemeta"

echo "Creating GitHub Issues for Forensic Audit..."
echo "============================================="
echo ""

# Step 1: Create labels if they don't exist
echo "Step 1: Creating labels..."
echo "-------------------------------------------"

# Define labels with colors
declare -A LABELS=(
    ["refactoring"]="0366d6"
    ["architecture"]="d4c5f9"
    ["priority:high"]="d93f0b"
    ["priority:medium"]="fbca04"
    ["priority:low"]="0e8a16"
    ["SOLID:SRP"]="c5def5"
    ["SOLID:OCP"]="c5def5"
    ["SOLID:DIP"]="c5def5"
    ["DRY"]="f9d0c4"
    ["KISS"]="f9d0c4"
    ["code-quality"]="bfdadc"
    ["SoC"]="f9d0c4"
    ["GRASP:Polymorphism"]="c5def5"
    ["testability"]="bfdadc"
    ["enhancement"]="a2eeef"
    ["CoC"]="f9d0c4"
    ["configuration"]="bfdadc"
    ["YAGNI"]="f9d0c4"
    ["architecture-review"]="d4c5f9"
    ["documentation"]="0075ca"
    ["user-facing"]="7057ff"
    ["easy-fix"]="0e8a16"
)

# Create each label if it doesn't exist
for label in "${!LABELS[@]}"; do
    color="${LABELS[$label]}"
    # Try to create label, ignore if it already exists
    gh label create "$label" --color "$color" --repo "$REPO" 2>/dev/null && echo "  ✓ Created label: $label" || echo "  - Label exists: $label"
done

echo ""
echo "Step 2: Creating issues..."
echo "-------------------------------------------"
echo ""

# Issue #1: JpegParser Refactoring
echo "Creating Issue #1: [CRITICAL] Refactor JpegParser..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor JpegParser - Extract Marker Handler Strategy Pattern" \
  --label "refactoring,architecture,priority:high,SOLID:SRP,SOLID:OCP" \
  --body '### Problem
`src/Parse/Jpeg/JpegParser.php` is a god class (2,200 LOC, 50+ methods) violating Single Responsibility Principle (SRP) and Open/Closed Principle (OCP). It handles 7+ distinct responsibilities:

- Marker sequence parsing
- APP segment extraction (APP1-APP13)
- EXIF blob assembly
- XMP packet stitching
- ICC profile handling
- Audio stream decoding
- MPF document parsing
- IPTC payload extraction
- FlashPix stream handling

**Evidence** (Lines 632-656):
```php
if ($marker === Marker::APP1) {
    $this->parseApp1Segment($stream, $segmentData, ...);
} elseif ($marker === Marker::APP2) {
    $this->parseApp2Segment($stream, $segmentData, ...);
} elseif ($marker === Marker::APP11) {
    // ... continues for 13 different APP markers
}
```

### Impact
- ❌ Cannot test individual parsing strategies in isolation
- ❌ Adding new APP marker types requires modifying critical parse loop (OCP violation)
- ❌ High cognitive load (2,200 LOC in single file)
- ❌ Violates "one reason to change" rule

### Acceptance Criteria
- [ ] Create `MarkerHandlerInterface` with methods:
  - `canHandle(int $marker): bool`
  - `handle(Stream $stream, string $data, ...): void`
- [ ] Implement 7 concrete handler classes:
  - `ExifSegmentHandler` (APP1 EXIF)
  - `XmpSegmentHandler` (APP1 XMP)
  - `IccProfileHandler` (APP2)
  - `AudioStreamHandler` (APP11)
  - `MpfDocumentHandler` (APP2 MPF)
  - `IptcSegmentHandler` (APP13)
  - `FlashPixHandler` (APP2 FlashPix)
- [ ] Create `MarkerHandlerRegistry` to manage handlers
- [ ] Refactor `JpegParser` to use handler registry instead of if-elseif chain
- [ ] Maintain backward compatibility with facade pattern
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] No duplicated code (JSCPD green)

### Implementation Notes
- Use Strategy pattern for handlers
- Handlers should be independent and testable
- Registry should support dynamic handler registration
- Keep streaming architecture (no full-file reads)
- Add EXIF spec references in handlers (latest + differing older)

### References
- FORENSIC_AUDIT.md: SOLID Violations #1, #4
- AGENTS.md: Section 4 (Guardrails), Section 12 (Compliance Catalog)

### Estimated Effort
3-5 days'

echo "✓ Issue #1 created"
echo ""

# Issue #2: IsoBmffParser Context Object
echo "Creating Issue #2: [CRITICAL] IsoBmffParser Parse Context..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor IsoBmffParser - Introduce Parse Context Object (DRY)" \
  --label "refactoring,DRY,priority:high,code-quality" \
  --body '### Problem
`src/Parse/IsoBmff/IsoBmffParser.php` has severe DRY violation: 20+ private methods repeat 8 identical reference parameters.

**Evidence** (Lines 477, 485, 487, 610, 629, 668, 720):
```php
private function parseMetaBox(
    BoxDescriptor $box,
    array &$exifBlobs,        // ← Repeated in 20+ methods
    array &$xmpBlobs,         // ← Repeated in 20+ methods
    array &$qtKeys,           // ← Repeated in 20+ methods
    array &$itemReferences,   // ← Repeated in 20+ methods
    array &$dataReferences,   // ← Repeated in 20+ methods
    array &$unresolvedItems,  // ← Repeated in 20+ methods
    array &$xmpHashes,        // ← Repeated in 20+ methods
    array &$qtDataAtoms = []  // ← Repeated in 20+ methods
): void
```

### Impact
- ❌ Parameter list management nightmare (8 params × 20 methods = 160 occurrences)
- ❌ Signature changes require updating 20+ method signatures
- ❌ Error-prone when adding new context state
- ❌ Violates DRY principle

### Acceptance Criteria
- [ ] Create `IsoBmffParseContext` class with public properties:
  - `array $exifBlobs = []`
  - `array $xmpBlobs = []`
  - `array $qtKeys = []`
  - `array $itemReferences = []`
  - `array $dataReferences = []`
  - `array $unresolvedItems = []`
  - `array $xmpHashes = []`
  - `array $qtDataAtoms = []`
- [ ] Refactor all 20+ parse methods to accept `IsoBmffParseContext $context` instead of 8 reference parameters
- [ ] Update method calls to use context object
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] Method signatures reduced from 9 params to 2 params

### Implementation Notes
- Context object simplifies adding new parse state in future
- Properties should be public for direct access (performance)
- No BC break needed (private methods only)
- Follow PSR-12 coding standards

### Example
```php
class IsoBmffParseContext
{
    public array $exifBlobs = [];
    public array $xmpBlobs = [];
    // ... other properties
}

private function parseMetaBox(
    BoxDescriptor $box,
    IsoBmffParseContext $context
): void {
    // Access via $context->exifBlobs, etc.
}
```

### References
- FORENSIC_AUDIT.md: DRY Violations #10
- AGENTS.md: Section 12 (Coding - descriptive variables)

### Estimated Effort
2-3 days'

echo "✓ Issue #2 created"
echo ""

# Issue #3: ParsedExif Domain Adapters
echo "Creating Issue #3: [CRITICAL] ParsedExif Domain Adapters..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor ParsedExif - Extract Domain Adapters (SRP, SoC)" \
  --label "refactoring,architecture,priority:high,SOLID:SRP,SoC" \
  --body '### Problem
`src/Exif/Model/ParsedExif.php` is a mega god class (5,066 LOC, 224 public methods) violating both Single Responsibility Principle and Separation of Concerns.

**Responsibilities**:
1. Data structure (IFD storage)
2. Value extraction (camera, lens, GPS, temporal, device, etc.)
3. Type conversion (rational, enum, datetime)
4. Text decoding (JIS, UTF-8, UTF-16)
5. Validation logic
6. UUID format validation
7. Maker note routing

**Evidence**:
- 224 public methods across 10+ domains
- 80+ private helper methods
- Couples to: ValueConverters, ExifCapabilities, JisTextDecoder, 15+ enum classes
- Lines 1-2000+ in single file

### Impact
- ❌ Single point of failure for all EXIF parsing
- ❌ Impossible to test domains in isolation
- ❌ High coupling to multiple subsystems
- ❌ Merge conflict nightmare in team development
- ❌ Violates "one reason to change" rule

### Acceptance Criteria
- [ ] Create domain adapter classes:
  - `CameraMetadataAdapter` (make, model, serial, firmware)
  - `GpsMetadataAdapter` (latitude, longitude, altitude, timestamp)
  - `TemporalMetadataAdapter` (datetime, timezone, offsets)
  - `LensMetadataAdapter` (make, model, focal length)
  - `ExposureMetadataAdapter` (ISO, aperture, shutter, EV)
  - `DeviceMetadataAdapter` (device info, software)
  - `ImageMetadataAdapter` (dimensions, orientation, compression)
- [ ] Move domain-specific methods to respective adapters
- [ ] Keep `ParsedExif` as data structure with delegation
- [ ] Maintain backward compatibility (facade pattern)
- [ ] Mark delegating methods as `@deprecated` with migration notes
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] No class exceeds 500 LOC

### Implementation Notes
- Phase 1: Create adapter classes
- Phase 2: Move methods to adapters
- Phase 3: Add delegation in ParsedExif with @deprecated
- Phase 4: Update documentation with migration guide
- Keep IFD tree structure in ParsedExif (core data)
- Adapters should be stateless (receive IFD data via constructor/methods)

### References
- FORENSIC_AUDIT.md: SOLID Violations #2, SoC Violations #18, GRASP Violations (High Cohesion)
- AGENTS.md: Section 1 (Principles), Section 12 (Compliance Catalog)

### Estimated Effort
5-7 days (high-risk refactoring, requires careful testing)'

echo "✓ Issue #3 created"
echo ""

# Issue #4: MakerNotes Decoder Duplication
echo "Creating Issue #4: [HIGH] MakerNotes Decoder Duplication..."
gh issue create \
  --repo "$REPO" \
  --title "[HIGH] Eliminate Code Duplication in MakerNotes Decoders (DRY)" \
  --label "refactoring,DRY,priority:medium,easy-fix" \
  --body '### Problem
Three decoder classes have identical `decode()` method implementations (copy-pasted code).

**Files**:
- `src/MakerNotes/CanonDecoder.php` (L29-36)
- `src/MakerNotes/NikonDecoder.php` (L29-36)
- `src/MakerNotes/SonyDecoder.php` (L29-36)

**Evidence**:
```php
// Exact duplicate in all three files
public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
{
    return new MakerNotesRecord(
        '"'"'Canon'"'"',  // Only difference: vendor name
        strlen($raw),
        sha1($raw)
    );
}
```

### Impact
- ❌ 3x maintenance burden
- ❌ Bug fixes must be applied in 3 places
- ❌ Violates DRY principle

### Acceptance Criteria
- [ ] Create abstract base class `AbstractSimpleDecoder implements MakerNotesDecoderInterface`
- [ ] Implement `decode()` method in base class
- [ ] Add abstract method `getVendorName(): string`
- [ ] Refactor Canon/Nikon/Sony decoders to extend base class
- [ ] Each decoder implements only `getVendorName()`
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] JSCPD shows no duplication in decoder classes

### Implementation Notes
- Simple refactoring, low risk
- Template Method pattern
- No BC break (implements same interface)

### Example
```php
abstract class AbstractSimpleDecoder implements MakerNotesDecoderInterface
{
    abstract protected function getVendorName(): string;
    
    public function decode(string $raw, string $make, ?string $model): MakerNotesRecord
    {
        return new MakerNotesRecord(
            $this->getVendorName(),
            strlen($raw),
            sha1($raw)
        );
    }
}

class CanonDecoder extends AbstractSimpleDecoder
{
    protected function getVendorName(): string {
        return '"'"'Canon'"'"';
    }
}
```

### References
- FORENSIC_AUDIT.md: DRY Violations #9
- AGENTS.md: Section 1 (SOLID), Section 12 (DRY adherence)

### Estimated Effort
1 day (quick win)'

echo "✓ Issue #4 created"
echo ""

# Issue #5: XmpParser Nested Logic
echo "Creating Issue #5: [HIGH] XmpParser Nested Logic..."
gh issue create \
  --repo "$REPO" \
  --title "[HIGH] Extract Nested Logic in XmpParser (KISS)" \
  --label "refactoring,KISS,priority:medium,code-quality" \
  --body '### Problem
`src/Parse/Xmp/XmpParser.php` has deeply nested loop logic (5+ levels) with high cyclomatic complexity.

**Evidence** (Lines 186-228):
```php
for ($parentDepth = $depth - 1; $parentDepth >= 0; --$parentDepth) {    // Level 1
    if (isset($listBuffers[$parentDepth])) {                             // Level 2
        if (($listKinds[$parentDepth] ?? '"'"''"'"') === '"'"'Alt'"'"') {               // Level 3
            if ($lang === '"'"''"'"') {                                         // Level 4
                throw new ParseError(                                   // Level 5
                    '"'"'Alt containers require xml:lang on children'"'"',
                    ParseError::XMP_ALT_MISSING_LANG
                );
            }
        }
    }
}
```

### Impact
- ❌ Cognitive overload (McCabe complexity > 15)
- ❌ Hard to understand control flow
- ❌ Error-prone modifications
- ❌ Difficult to test edge cases

### Acceptance Criteria
- [ ] Extract nested loop logic into helper methods:
  - `findParentListBuffer(array $listBuffers, array $listKinds, int $depth, string $lang): ?array`
  - `validateAltContainerLang(string $kind, string $lang): void`
- [ ] Reduce nesting to maximum 3 levels
- [ ] Cyclomatic complexity ≤ 10 for all methods
- [ ] Add unit tests for extracted helper methods
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green

### Implementation Notes
- Extract Method refactoring
- Helper methods should be private
- Preserve exact behavior (characterization tests)
- Add descriptive method names

### References
- FORENSIC_AUDIT.md: KISS Violations #13
- AGENTS.md: Section 12 (no nested ternaries, readable code)

### Estimated Effort
2 days'

echo "✓ Issue #5 created"
echo ""

# Issue #6: Dependency Injection
echo "Creating Issue #6: [HIGH] Dependency Injection for Parsers..."
gh issue create \
  --repo "$REPO" \
  --title "[HIGH] Introduce Dependency Injection for Parsers (SOLID:DIP)" \
  --label "refactoring,architecture,SOLID:DIP,priority:medium,testability" \
  --body '### Problem
Multiple classes directly instantiate concrete parsers violating Dependency Inversion Principle (depend on abstractions, not concretions).

**Evidence**:

1. `src/Exif/Factory/ValueFactory.php` (L285):
```php
$iccProfile = (new IccParser())->decode($iccBlob);
```

2. `src/Factory/StructuredMetadataBuilder.php` (L26):
```php
public function __construct(
    private ValueFactory $valueFactory = new ValueFactory()
)
```

3. `src/MetadataReader.php` (L54-59, L112, L210):
Multiple `new Parser()` instantiations

### Impact
- ❌ Cannot inject mocks for testing
- ❌ Cannot substitute alternative implementations
- ❌ Tight coupling to concrete classes
- ❌ Hard to test in isolation

### Acceptance Criteria
- [ ] Create parser interfaces:
  - `IccParserInterface`
  - `TiffExifParserInterface`
  - `XmpParserInterface`
  - `IptcParserInterface`
  - `JpegParserInterface`
  - `IsoBmffParserInterface`
- [ ] Refactor concrete parsers to implement interfaces
- [ ] Inject parsers via constructor (remove default `new` instantiation)
- [ ] Update all callers to inject dependencies
- [ ] Add factory classes if needed for complex creation
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] Unit tests can mock parser dependencies

### Implementation Notes
- Interfaces should be minimal (ISP)
- Keep backward compatibility with default factory
- Use constructor property promotion
- Follow AGENTS.md conventions (interfaces in root, descriptive names)

### References
- FORENSIC_AUDIT.md: SOLID Violations (DIP) #8
- AGENTS.md: Section 1 (SOLID), Section 12 (sensible interfaces)

### Estimated Effort
3-4 days'

echo "✓ Issue #6 created"
echo ""

# Issue #7: GpsConverter Encoding Decoders
echo "Creating Issue #7: [MEDIUM] GpsConverter Encoding Decoders..."
gh issue create \
  --repo "$REPO" \
  --title "[MEDIUM] Extract GpsConverter Encoding Decoders (DRY)" \
  --label "refactoring,DRY,priority:low,code-quality" \
  --body '### Problem
`src/Exif/Converters/GpsConverter.php` has three methods with identical structure, only differing in encoding name.

**Evidence** (Lines ~350-400):
```php
private function decodeUndefinedUtf8(string $payload): ?string {
    $decoded = iconv('"'"'UTF-8'"'"', '"'"'UTF-8//IGNORE'"'"', $payload);
    // ... identical validation logic
}

private function decodeUndefinedUnicode(string $payload): ?string {
    $decoded = iconv('"'"'UTF-16'"'"', '"'"'UTF-8//IGNORE'"'"', $payload);
    // ... identical validation logic
}

private function decodeUndefinedJis(string $payload): ?string {
    $decoded = iconv('"'"'JIS'"'"', '"'"'UTF-8//IGNORE'"'"', $payload);
    // ... identical validation logic
}
```

### Impact
- ⚠️ Duplicated validation logic (3x)
- ⚠️ Bug fixes need 3 changes
- ⚠️ Violates DRY

### Acceptance Criteria
- [ ] Create generic method `decodeUndefinedWithEncoding(string $payload, string $sourceEncoding): ?string`
- [ ] Extract common validation logic into generic method
- [ ] Refactor three existing methods to use generic decoder
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] JSCPD shows no duplication

### Implementation Notes
- Simple refactoring
- Low risk (private methods)
- Consider using enum for encodings

### References
- FORENSIC_AUDIT.md: DRY Violations #11
- AGENTS.md: Section 12 (DRY adherence)

### Estimated Effort
1 day'

echo "✓ Issue #7 created"
echo ""

# Issue #8: Configuration Objects
echo "Creating Issue #8: [MEDIUM] Configuration Objects for Parser Limits..."
gh issue create \
  --repo "$REPO" \
  --title "[MEDIUM] Add Configuration Objects for Parser Limits (CoC)" \
  --label "enhancement,CoC,priority:low,configuration" \
  --body '### Problem
Parser classes have hardcoded magic numbers for size limits, violating Convention over Configuration.

**Evidence**:

`src/Parse/Jpeg/JpegParser.php`:
```php
private const int MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB hardcoded
private const int EXTENDED_XMP_GUID_LENGTH = 32;
private const int FLASHPIX_MAX_STREAM_SIZE = 16_777_216; // 16 MiB hardcoded
```

### Impact
- ⚠️ Cannot configure limits without code modification
- ⚠️ Hard to adjust for specific use cases
- ⚠️ Testing with custom limits difficult

### Acceptance Criteria
- [ ] Create configuration classes:
  - `JpegParserConfig` (segment limits, GUID length, stream sizes)
  - `TiffParserConfig` (if applicable)
  - `IsoBmffParserConfig` (if applicable)
- [ ] Add constructor parameters accepting config objects (with defaults)
- [ ] Replace hardcoded constants with config property access
- [ ] Update documentation with configuration examples
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] Add integration test with custom config

### Implementation Notes
- Use readonly config objects (immutable)
- Provide sensible defaults (current hardcoded values)
- No BC break (config optional with defaults)

### References
- FORENSIC_AUDIT.md: CoC Violations #19
- AGENTS.md: Section 4 (Guards - hard max limits)

### Estimated Effort
2-3 days'

echo "✓ Issue #8 created"
echo ""

# Issue #9: Replace instanceof with Polymorphism
echo "Creating Issue #9: [MEDIUM] Replace instanceof with Polymorphism..."
gh issue create \
  --repo "$REPO" \
  --title "[MEDIUM] Replace instanceof Chains with Polymorphism (GRASP)" \
  --label "refactoring,GRASP:Polymorphism,priority:medium,architecture" \
  --body '### Problem
Multiple classes use sequential `instanceof` type checks instead of polymorphic dispatch, violating GRASP Polymorphism pattern.

**Evidence**:

`src/MakerNotes/Apple/KeyedArchiveUnarchiver.php` (Lines 89-140):
```php
if ($value instanceof ApplePlistDictionary) {
    // Dictionary logic
} else if ($value instanceof ApplePlistArray) {
    // Array logic
} else if ($value instanceof ApplePlistScalar) {
    // Scalar logic
}
```

**Similar violations**:
- `src/MakerNotes/AppleDecoder.php` (~470-510)
- `src/Parse/Tiff/TiffExifParser.php` (~365-395)
- `src/Exif/Converters/GpsConverter.php` (~197, 210, 220)

### Impact
- ⚠️ Not extensible (adding new types requires modifying existing code)
- ⚠️ Violates Open/Closed Principle
- ⚠️ Type-checking logic spread across codebase

### Acceptance Criteria
- [ ] Add polymorphic method to `ApplePlistValueInterface`:
  - `resolveValue(KeyedArchiveUnarchiver $unarchiver): mixed`
- [ ] Implement `resolveValue()` in all concrete classes:
  - `ApplePlistDictionary`
  - `ApplePlistArray`
  - `ApplePlistScalar`
- [ ] Replace `instanceof` chains with polymorphic calls
- [ ] Consider similar refactoring for other type-check chains
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green

### Implementation Notes
- Visitor or Strategy pattern
- Each type knows how to resolve itself
- Extensible for new types

### References
- FORENSIC_AUDIT.md: GRASP Violations (Polymorphism) #20
- AGENTS.md: Section 12 (no nested ternaries, SOLID adherence)

### Estimated Effort
3-4 days (affects multiple files)'

echo "✓ Issue #9 created"
echo ""

# Issue #10: Evaluate Factory Necessity
echo "Creating Issue #10: [LOW] Evaluate Factory Necessity..."
gh issue create \
  --repo "$REPO" \
  --title "[LOW] Evaluate Factory Necessity (YAGNI)" \
  --label "refactoring,YAGNI,priority:low,architecture-review" \
  --body '### Problem
`src/Exif/Factory/ValueFactory.php` instantiates 12 separate factory classes, each with a single `create()` method. This may be over-engineered.

**Evidence** (Lines 85-97):
```php
private CameraFactory $cameraFactory = new CameraFactory(),
private LensFactory $lensFactory = new LensFactory(),
private ExposureFactory $exposureFactory = new ExposureFactory(),
// ... 9 more factories
```

Each sub-factory has trivial implementation:
```php
// CameraFactory.php
public function create(Metadata $metadata): Camera {
    $exifDocument = $metadata->exifDoc;
    return new Camera($exifDocument?->cameraMake(), ...);
}
```

### Impact
- ⚠️ Unnecessary abstraction layers
- ⚠️ All factories instantiated even if not used
- ⚠️ Could be inline methods or static functions

### Acceptance Criteria
- [ ] Evaluate each of 12 factories:
  - Does it have complex creation logic?
  - Is it reused elsewhere?
  - Does it need to be mocked in tests?
- [ ] Consider alternatives:
  - Inline creation in ValueFactory
  - Builder pattern
  - Lazy initialization
- [ ] Document decision (keep or remove)
- [ ] If removing: refactor to inline methods
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green

### Implementation Notes
- This is a research/evaluation task
- May not result in code changes
- Document rationale for keeping factories if justified
- Consider lazy initialization if factories are heavy

### References
- FORENSIC_AUDIT.md: YAGNI Violations #16
- AGENTS.md: Section 1 (YAGNI principle)

### Estimated Effort
2-3 days (analysis + potential refactoring)'

echo "✓ Issue #10 created"
echo ""

# Issue #11: Migration Guide
echo "Creating Issue #11: [DOCUMENTATION] Migration Guide..."
gh issue create \
  --repo "$REPO" \
  --title "[DOCUMENTATION] Create Migration Guide for Refactored APIs" \
  --label "documentation,priority:medium,user-facing" \
  --body '### Problem
As refactorings are completed (especially ParsedExif split and JpegParser extraction), users need guidance on migrating from deprecated APIs.

### Acceptance Criteria
- [ ] Create `MIGRATION.md` document with:
  - Version-by-version breaking changes
  - Deprecated method mapping to new APIs
  - Code examples (before/after)
  - Upgrade checklist
- [ ] Add `@deprecated` annotations with migration notes in code
- [ ] Update README.md with link to migration guide
- [ ] Add changelog entries for each breaking change
- [ ] Document backward compatibility period

### Example Structure
```markdown
# Migration Guide

## v2.0.0

### ParsedExif Split (Breaking)

**Deprecated:**
```php
$exif->cameraMake();
$exif->cameraModel();
```

**New:**
```php
$adapter = new CameraMetadataAdapter($exif);
$adapter->getMake();
$adapter->getModel();
```

### JpegParser Handlers (Non-breaking)

Old internal methods are now separate handlers but public API unchanged.
```

### References
- FORENSIC_AUDIT.md: Section 13 (Migration Strategy)
- AGENTS.md: Section 12 (descriptive, English docs)

### Estimated Effort
1-2 days per major refactoring'

echo "✓ Issue #11 created"
echo ""

echo "============================================="
echo "All 11 issues created successfully!"
echo ""
echo "Summary:"
echo "  - 3 Critical priority issues (#1, #2, #3)"
echo "  - 4 High priority issues (#4, #5, #6, #9)"
echo "  - 3 Medium priority issues (#7, #8, #10)"
echo "  - 1 Documentation issue (#11)"
echo ""
echo "Next steps:"
echo "  1. Review issues in GitHub"
echo "  2. Assign to developers"
echo "  3. Set milestones as needed"
echo "  4. Start with Quick Wins: Issues #4 and #7"
