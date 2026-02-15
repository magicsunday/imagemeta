#!/bin/bash

# Vollständiges Shell-Skript zur Erstellung ALLER 38 GitHub Issues
# Basierend auf RE_AUDIT_REPORT.md (2026-02-15)
# Usage: ./create_all_38_issues.sh

# Nicht bei Fehlern abbrechen - wir wollen alle Issues erstellen
# set -e würde nach dem ersten Fehler stoppen

REPO="magicsunday/imagemeta"

echo "========================================================================"
echo "GitHub Issues für ALLE 38 ungelösten Violations erstellen"
echo "========================================================================"
echo ""
echo "Basierend auf: RE_AUDIT_REPORT.md (2026-02-15)"
echo "Repository: $REPO"
echo "Total: 38 Issues"
echo ""

# Schritt 1: Labels erstellen
echo "Schritt 1: Labels erstellen..."
echo "------------------------------------------------------------------------"

declare -A LABELS=(
    ["refactoring"]="0366d6"
    ["architecture"]="d4c5f9"
    ["priority:critical"]="b60205"
    ["priority:high"]="d93f0b"
    ["priority:medium"]="fbca04"
    ["priority:low"]="0e8a16"
    ["SOLID:SRP"]="c5def5"
    ["SOLID:OCP"]="c5def5"
    ["SOLID:LSP"]="c5def5"
    ["SOLID:ISP"]="c5def5"
    ["SOLID:DIP"]="c5def5"
    ["DRY"]="f9d0c4"
    ["KISS"]="f9d0c4"
    ["YAGNI"]="f9d0c4"
    ["LoD"]="f9d0c4"
    ["SoC"]="f9d0c4"
    ["CoC"]="f9d0c4"
    ["GRASP:Polymorphism"]="c5def5"
    ["code-quality"]="bfdadc"
    ["testability"]="bfdadc"
    ["enhancement"]="a2eeef"
    ["configuration"]="bfdadc"
    ["documentation"]="0075ca"
    ["user-facing"]="7057ff"
    ["easy-fix"]="0e8a16"
    ["god-class"]="b60205"
    ["tech-debt"]="d93f0b"
)

for label in "${!LABELS[@]}"; do
    color="${LABELS[$label]}"
    gh label create "$label" --color "$color" --repo "$REPO" 2>/dev/null && echo "  ✓ $label" || echo "  - $label (exists)"
done

echo ""
echo "Schritt 2: Issues erstellen..."
echo "------------------------------------------------------------------------"
echo ""

CREATED=0
FAILED=0

# Helper function
create_issue() {
    local num=$1
    local title=$2
    local labels=$3
    local body=$4
    
    echo "[$num/38] Erstelle: $title"
    
    # Explizit Fehler ignorieren und weitermachen
    local output
    local exit_code
    
    set +e  # Temporär Fehlerbehandlung deaktivieren
    output=$(gh issue create --repo "$REPO" --title "$title" --label "$labels" --body "$body" 2>&1)
    exit_code=$?
    set -e  # Wieder aktivieren (hat aber eh keinen Effekt mehr ohne set -e am Anfang)
    
    if [ $exit_code -eq 0 ]; then
        echo "  ✓ Erfolgreich: $output"
        ((CREATED++)) || true
    else
        echo "  ✗ Fehler: $output"
        ((FAILED++)) || true
    fi
    echo ""
    
    # Immer erfolgreich zurückkehren, damit das Skript weiterläuft
    return 0
}

# ============================================================================
# SOLID: Single Responsibility Principle (3 Issues)
# ============================================================================

create_issue 1 \
  "[CRITICAL] Refactor JpegParser - Extract Handler Strategy Pattern (SRP)" \
  "priority:critical,refactoring,architecture,SOLID:SRP,SOLID:OCP,god-class,tech-debt" \
  '## Violation Summary

**Principle**: SOLID - Single Responsibility Principle (SRP)  
**File**: `src/Parse/Jpeg/JpegParser.php`  
**Severity**: 🔴 CRITICAL  
**Status**: UNRESOLVED (Re-Audit 2026-02-15)

### Current Metrics
- **LOC**: 2,651
- **Methods**: 50+
- **Responsibilities**: 7+ distinct concerns

### Problem
God class handling entire JPEG parsing pipeline: marker parsing, APP segments (APP1-13), EXIF assembly, XMP stitching, ICC profiles, audio, MPF, IPTC, FlashPix.

**Evidence** (Lines 632-656):
```php
if ($marker === Marker::APP1) {
    $this->parseApp1Segment(...);
} elseif ($marker === Marker::APP2) {
    // ... continues for 13 marker types
}
```

### Solution
Extract Strategy Pattern:
- Create `MarkerHandlerInterface`
- Implement 7 concrete handlers (ExifSegmentHandler, XmpSegmentHandler, etc.)
- Create `MarkerHandlerRegistry`
- Refactor JpegParser to use registry

### Acceptance Criteria
- [ ] Handler interface + 7 implementations
- [ ] Registry pattern for handler lookup
- [ ] JpegParser reduced to ≤ 500 LOC (from 2,651)
- [ ] Backward compatibility maintained
- [ ] All tests pass (≥ 90% coverage)
- [ ] PHPStan level max green

### Effort
3-5 days

### References
- RE_AUDIT_REPORT.md: Issue #1
- FORENSIC_AUDIT.md: SOLID #1, #4'

create_issue 2 \
  "[CRITICAL] Refactor TiffExifParser - Split Mega Class into Components (SRP)" \
  "priority:critical,refactoring,architecture,SOLID:SRP,god-class,tech-debt" \
  '## Violation Summary

**File**: `src/Parse/Tiff/TiffExifParser.php`  
**LOC**: 9,847 (nearly 10K!)  
**Methods**: 170  
**Status**: UNRESOLVED

### Problem
Largest file in codebase handling: TIFF/BigTIFF parsing, IFD traversal, type conversion, validation, maker notes, DNG tags.

### Solution
Split into components:
- `IfdTreeParser` - Structure parsing
- `TagMetadataRegistry` - Tag definitions (externalize to JSON)
- `DataTypeConverter` - TIFF types
- `MakerNotesResolver` - Vendor routing
- `ValueValidator` - Bounds checking

### Acceptance Criteria
- [ ] 5 focused components created
- [ ] TiffExifParser reduced to ≤ 500 LOC (from 9,847)
- [ ] Tag metadata externalized to JSON
- [ ] All tests pass (≥ 90% coverage)

### Effort
5-7 days (HIGH RISK)

### References
- RE_AUDIT_REPORT.md: Issue #2'

create_issue 3 \
  "[CRITICAL] Refactor ParsedExif - Extract Domain Adapters (SRP/SoC)" \
  "priority:critical,refactoring,architecture,SOLID:SRP,SoC,god-class,tech-debt" \
  '## Violation Summary

**File**: `src/Exif/Model/ParsedExif.php`  
**LOC**: 5,066  
**Methods**: 275 (was 224 - GROWING!)  
**Trend**: ⚠️ +51 methods (+23%)  
**Status**: UNRESOLVED, DETERIORATING

### Problem
God class mixing data structure + extraction + conversion + validation. **Getting worse!**

### Solution
Extract 7 domain adapters:
- CameraMetadataAdapter
- GpsMetadataAdapter
- TemporalMetadataAdapter
- LensMetadataAdapter
- ExposureMetadataAdapter
- DeviceMetadataAdapter
- ImageMetadataAdapter

### Acceptance Criteria
- [ ] 7 domain adapters created (each ≤ 300 LOC)
- [ ] ParsedExif reduced to ≤ 1,000 LOC
- [ ] Method count reduced to ≤ 100 (from 275)
- [ ] Backward compatibility with @deprecated facades
- [ ] Migration guide created

### Effort
5-7 days (CRITICAL due to growth)

### References
- RE_AUDIT_REPORT.md: Issue #3'

# ============================================================================
# SOLID: Open/Closed Principle (3 Issues)
# ============================================================================

create_issue 4 \
  "[HIGH] JpegParser - Replace if-elseif Chain with Strategy Pattern (OCP)" \
  "priority:high,refactoring,SOLID:OCP,tech-debt" \
  '## Violation

**File**: `src/Parse/Jpeg/JpegParser.php` (Lines 632-656)

### Problem
if-elseif chain for 13 marker types. Adding new markers requires modifying parse loop.

### Solution
Part of Issue #1 (SRP-1). Strategy pattern via handler registry enables extension without modification.

### Status
Blocked by Issue #1

### Effort
Included in Issue #1'

create_issue 5 \
  "[MEDIUM] MetadataReader - Replace Container Type Switch with Polymorphism (OCP)" \
  "priority:medium,refactoring,SOLID:OCP" \
  '## Violation

**File**: `src/MetadataReader.php` (Lines 86-89)

```php
return match ($type) {
    ContainerType::JPEG => ...,
    ContainerType::ISOBMFF => ...,
};
```

### Problem
New container formats require code modification.

### Solution
Create `ContainerParserInterface` + polymorphic factory.

### Effort
2-3 days'

create_issue 6 \
  "[MEDIUM] ValueFactory - Remove Hard-Wired Factory Dependencies (OCP/DIP)" \
  "priority:medium,refactoring,SOLID:OCP,SOLID:DIP" \
  '## Violation

**File**: `src/Exif/Factory/ValueFactory.php` (Lines 85-98)

### Problem
12 factories hard-wired in constructor. Adding new metadata domains requires modifying constructor.

### Solution
Registry pattern for dynamic factory registration.

### Effort
2 days'

# ============================================================================
# SOLID: Liskov Substitution Principle (4 Issues)
# ============================================================================

create_issue 7 \
  "[MEDIUM] ImageFactory - Remove Type Guard with Inconsistent Returns (LSP)" \
  "priority:medium,refactoring,SOLID:LSP,easy-fix" \
  '## Violation

**File**: `src/Exif/Factory/ImageFactory.php` (Lines 75-76)

```php
if (!$exifDocument instanceof ParsedExif) {
    return new Image(...with nulls...);
}
```

### Problem
Returns different object types based on runtime check. Violates substitutability.

### Solution
Use null object pattern or strict typing.

### Effort
4 hours'

create_issue 8 \
  "[MEDIUM] RegionsFactory - Fix Type Check Early Return (LSP)" \
  "priority:medium,refactoring,SOLID:LSP,easy-fix" \
  '## Violation

**File**: `src/Exif/Factory/RegionsFactory.php` (Line 71)

### Solution
Same as Issue #7: null object pattern.

### Effort
4 hours'

create_issue 9 \
  "[MEDIUM] LensFactory - Remove Defensive instanceof Check (LSP)" \
  "priority:medium,refactoring,SOLID:LSP,easy-fix" \
  '## Violation

**File**: `src/Exif/Factory/LensFactory.php` (Lines 35-44)

### Solution
Strict typing instead of defensive checks.

### Effort
4 hours'

create_issue 10 \
  "[MEDIUM] GpsFactory - Fix Inconsistent Return Types (LSP)" \
  "priority:medium,refactoring,SOLID:LSP,easy-fix" \
  '## Violation

**File**: `src/Exif/Factory/GpsFactory.php` (Lines 60-62)

### Solution
Consistent return type handling.

### Effort
4 hours'

# ============================================================================
# SOLID: Interface Segregation Principle (1 Issue)
# ============================================================================

create_issue 11 \
  "[MEDIUM] Split BinaryReadAccessInterface into Focused Interfaces (ISP)" \
  "priority:medium,refactoring,SOLID:ISP,architecture" \
  '## Violation

**File**: `src/Core/BinaryReadAccessInterface.php` (Lines 21-67)

### Problem
Fat interface with 8 methods. Not all implementations use all methods.

### Solution
```php
interface SequentialReaderInterface {
    public function read(int $length): string;
}

interface SeekableReaderInterface extends SequentialReaderInterface {
    public function seek(int $offset, int $whence): void;
}

interface BigEndianReaderInterface {
    public function readU16BE(): int;
    public function readU32BE(): int;
}
```

### Effort
1 day'

# ============================================================================
# SOLID: Dependency Inversion Principle (6 Issues)
# ============================================================================

create_issue 12 \
  "[HIGH] ValueFactory - Inject IccParser Instead of Direct Instantiation (DIP)" \
  "priority:high,refactoring,SOLID:DIP,testability" \
  '## Violation

**File**: `src/Exif/Factory/ValueFactory.php` (Line 285)

```php
$iccProfile = (new IccParser())->decode($iccBlob);
```

### Problem
Cannot inject mocks for testing. Tight coupling.

### Solution
Constructor injection with IccParserInterface.

### Effort
1 day'

create_issue 13 \
  "[HIGH] StructuredMetadataBuilder - Inject ValueFactory (DIP)" \
  "priority:high,refactoring,SOLID:DIP" \
  '## Violation

**File**: `src/Factory/StructuredMetadataBuilder.php` (Line 26)

```php
public function __construct(
    private ValueFactory $valueFactory = new ValueFactory()
)
```

### Solution
Remove default instantiation, require injection.

### Effort
4 hours'

create_issue 14 \
  "[HIGH] MetadataReader - Inject All Parser Dependencies (DIP)" \
  "priority:high,refactoring,SOLID:DIP,testability" \
  '## Violation

**File**: `src/MetadataReader.php` (Lines 54-59, 112, 210)

### Problem
Direct instantiation of: TiffExifParser, XmpParser, IptcParser, JpegParser, IsoBmffParser.

### Solution
Create parser interfaces + constructor injection.

### Effort
3-4 days'

create_issue 15 \
  "[MEDIUM] ConverterFactory - Fix Manual Dependency Bootstrapping (DIP)" \
  "priority:medium,refactoring,SOLID:DIP" \
  '## Violation

**File**: `src/Exif/Converters/ConverterFactory.php` (Lines 49-79)

### Solution
Use dependency injection instead of manual wiring.

### Effort
1 day'

create_issue 16 \
  "[LOW] Create Parser Interfaces for Dependency Injection (DIP)" \
  "priority:low,enhancement,SOLID:DIP,testability" \
  '## Task

Create interfaces for all parsers:
- IccParserInterface
- TiffExifParserInterface
- XmpParserInterface
- IptcParserInterface
- JpegParserInterface
- IsoBmffParserInterface

### Effort
1 day'

create_issue 17 \
  "[LOW] Add Factory Classes for Complex Parser Creation (DIP)" \
  "priority:low,enhancement,SOLID:DIP" \
  '## Task

Add factory classes to handle complex parser instantiation with dependencies.

### Effort
1 day'

# ============================================================================
# DRY Violations (4 remaining - 1 already resolved)
# ============================================================================

create_issue 18 \
  "[CRITICAL] IsoBmffParser - Extract Parse Context Object (DRY)" \
  "priority:critical,refactoring,DRY,code-quality,easy-fix" \
  '## Violation

**File**: `src/Parse/IsoBmff/IsoBmffParser.php`  
**Pattern**: 8 parameters repeated in 11+ methods

### Problem
```php
private function parseMetaBox(
    BoxDescriptor $box,
    array &$exifBlobs,
    array &$xmpBlobs,
    array &$qtKeys,
    array &$itemReferences,
    array &$dataReferences,
    array &$unresolvedItems,
    array &$xmpHashes,
    array &$qtDataAtoms = []
): void
```

### Solution
```php
class IsoBmffParseContext {
    public array $exifBlobs = [];
    public array $xmpBlobs = [];
    // ...
}

private function parseMetaBox(
    BoxDescriptor $box,
    IsoBmffParseContext $context
): void
```

### Effort
2-3 days (Quick Win!)

### References
- RE_AUDIT_REPORT.md: DRY-1'

create_issue 19 \
  "[MEDIUM] GpsConverter - Extract Generic Encoding Decoder (DRY)" \
  "priority:medium,refactoring,DRY,easy-fix" \
  '## Violation

**File**: `src/Exif/Converters/GpsConverter.php` (Lines ~350-400)

### Problem
3 identical methods: decodeUndefinedUtf8(), decodeUndefinedUnicode(), decodeUndefinedJis()

### Solution
```php
private function decodeUndefinedWithEncoding(
    string $payload,
    string $sourceEncoding
): ?string
```

### Effort
1 day (Quick Win!)'

create_issue 20 \
  "[MEDIUM] TiffExifParser - Externalize Tag Metadata Definitions (DRY)" \
  "priority:medium,refactoring,DRY,enhancement" \
  '## Violation

**File**: `src/Parse/Tiff/TiffExifParser.php` (Lines 300-1200)

### Problem
200+ repetitive tag definitions in massive array literal.

### Solution
Move to `resources/exif-tags.json` + TagMetadataRegistry loader.

### Effort
2 days'

create_issue 21 \
  "[LOW] RationalConverter - Consolidate Repeated Null/Type Checks (DRY)" \
  "priority:low,refactoring,DRY" \
  '## Violation

**File**: `src/Exif/Converters/RationalConverter.php`

### Problem
Repeated null/type checking patterns.

### Solution
Extract helper method for validation.

### Effort
4 hours'

# ============================================================================
# KISS Violations (4 Issues)
# ============================================================================

create_issue 22 \
  "[MEDIUM] XmpParser - Complete Nesting Reduction (KISS)" \
  "priority:medium,refactoring,KISS,code-quality" \
  '## Violation

**File**: `src/Parse/Xmp/XmpParser.php` (Lines 186-243)  
**Status**: 🟡 PARTIALLY IMPROVED (helpers extracted, but 6-level nesting remains)

### Problem
Main parse() method still has 6-level nesting (target: ≤ 3).

### Progress
✅ Helper methods extracted  
⚠️ Main method still complex

### Remaining Work
Extract more control flow to focused methods.

### Effort
2 days'

create_issue 23 \
  "[MEDIUM] IsoBmffParser - Extract Conditional Chain Helper Methods (KISS)" \
  "priority:medium,refactoring,KISS" \
  '## Violation

**File**: `src/Parse/IsoBmff/IsoBmffParser.php` (Lines 841-900, 833-859)

### Problem
Deeply nested conditions with repeated array key checks.

### Solution
```php
private function setKeyIfMissing(array &$array, string $key, mixed $value): void
```

### Effort
1 day'

create_issue 24 \
  "[LOW] TiffExifParser - Extract Nested Loop Logic (KISS)" \
  "priority:low,refactoring,KISS" \
  '## Violation

**File**: `src/Parse/Tiff/TiffExifParser.php`

### Problem
Multiple 3+ level nested loops.

### Solution
Extract to helper methods.

### Effort
2 days'

create_issue 25 \
  "[LOW] AppleDecoder - Consider Splitting Large File (KISS)" \
  "priority:low,refactoring,KISS" \
  '## Observation

**File**: `src/MakerNotes/AppleDecoder.php`  
**LOC**: 1,999

### Note
File handles: binary plist + keyed archive + semantic style.

### Potential Split
- BinaryPlistDecoder
- KeyedArchiveUnarchiver
- SemanticStyleProcessor

### Effort
3-4 days'

# ============================================================================
# YAGNI Violations (3 Issues)
# ============================================================================

create_issue 26 \
  "[LOW] Evaluate Necessity of 12 Separate Factory Classes (YAGNI)" \
  "priority:low,YAGNI,architecture-review" \
  '## Observation

**File**: `src/Exif/Factory/ValueFactory.php` (Lines 85-97)

### Problem
12 factories (CameraFactory, LensFactory, etc.) - each with single create() method.

### Questions
- Are they reused elsewhere?
- Do they need to be mocked in tests?
- Or could they be inline methods?

### Effort
2-3 days (research + potential refactoring)'

create_issue 27 \
  "[LOW] Review Sub-Factory Trivial Wrappers (YAGNI)" \
  "priority:low,YAGNI" \
  '## Observation

Many factory classes are trivial wrappers around object construction.

### Solution
Evaluate if abstraction is justified or if inline construction suffices.

### Effort
1 day'

create_issue 28 \
  "[LOW] Simplify AppleMakerNotes Singleton Cache (YAGNI)" \
  "priority:low,YAGNI" \
  '## Observation

**File**: `src/MakerNotes/AppleMakerNotes.php`

### Problem
Singleton pattern with static cache - may be over-engineered.

### Solution
Evaluate if simpler approach suffices.

### Effort
1 day'

# ============================================================================
# Law of Demeter (LoD) Violations (3 Issues)
# ============================================================================

create_issue 29 \
  "[MEDIUM] ValueFactory - Add Delegation Methods for Property Chains (LoD)" \
  "priority:medium,refactoring,LoD" \
  '## Violation

**File**: `src/Exif/Factory/ValueFactory.php` (Lines 127-130)

```php
$appleMakerNotes = $metadata->makerNotes?->apple;
```

### Solution
```php
// In Metadata class
public function getAppleMakerNotes(): ?AppleMakerNotes
```

### Effort
1 day'

create_issue 30 \
  "[MEDIUM] RegionsFactory - Avoid Nested Object Access (LoD)" \
  "priority:medium,refactoring,LoD" \
  '## Violation

**File**: `src/Exif/Factory/RegionsFactory.php`

### Solution
Add delegation methods to avoid property chains.

### Effort
4 hours'

create_issue 31 \
  "[LOW] ExifReader - Review Facade Delegation Pattern (LoD)" \
  "priority:low,LoD" \
  '## Observation

**File**: `src/ExifReader.php`

### Task
Review if facade properly encapsulates delegate access.

### Effort
4 hours'

# ============================================================================
# Separation of Concerns (SoC) Violations (3 Issues)
# ============================================================================

create_issue 32 \
  "[CRITICAL] ParsedExif - Separate Parsing from Conversion (SoC)" \
  "priority:critical,refactoring,SoC" \
  '## Violation

Same as Issue #3 (SRP-3). ParsedExif mixes data structure + parsing + validation + conversion.

### Status
Tracked in Issue #3'

create_issue 33 \
  "[CRITICAL] JpegParser - Separate I/O from Business Logic (SoC)" \
  "priority:critical,refactoring,SoC" \
  '## Violation

Same as Issue #1 (SRP-1). JpegParser mixes binary I/O + format detection + segment extraction.

### Status
Tracked in Issue #1'

create_issue 34 \
  "[MEDIUM] MetadataReader - Reduce Parser Coupling (SoC)" \
  "priority:medium,refactoring,SoC" \
  '## Violation

**File**: `src/MetadataReader.php`

### Problem
High coupling to multiple parsers and formats.

### Solution
Extract format-specific logic to dedicated classes.

### Effort
2-3 days'

# ============================================================================
# Convention over Configuration (CoC) Violations (6 Issues)
# ============================================================================

create_issue 35 \
  "[MEDIUM] JpegParser - Create Configuration Object for Hardcoded Limits (CoC)" \
  "priority:medium,enhancement,CoC,configuration" \
  '## Violation

**File**: `src/Parse/Jpeg/JpegParser.php`

```php
private const int MAX_APP_SEGMENT_SIZE = 4_194_304; // 4 MiB
private const int FLASHPIX_MAX_STREAM_SIZE = 16_777_216; // 16 MiB
```

### Solution
```php
class JpegParserConfig {
    public function __construct(
        public int $maxAppSegmentSize = 4_194_304,
        public int $maxFlashPixStreamSize = 16_777_216,
    ) {}
}
```

### Effort
2-3 days'

create_issue 36 \
  "[LOW] RegionsFactory - Externalize Namespace URIs (CoC)" \
  "priority:low,CoC,configuration" \
  '## Violation

**File**: `src/Exif/Factory/RegionsFactory.php`

### Solution
Move namespace URIs to configuration.

### Effort
4 hours'

create_issue 37 \
  "[LOW] PhotoCalculator - Make Sensor Constants Configurable (CoC)" \
  "priority:low,CoC" \
  '## Violation

Hardcoded sensor calculation constants.

### Solution
Configuration object or constants registry.

### Effort
4 hours'

create_issue 38 \
  "[LOW] ParsedExif - Replace Magic Numbers with Named Constants (CoC)" \
  "priority:low,CoC" \
  '## Violation

**File**: `src/Exif/Model/ParsedExif.php`

### Solution
Replace numeric literals with named constants.

### Effort
1 day'

# ============================================================================
# Summary
# ============================================================================

echo "========================================================================"
echo "Zusammenfassung"
echo "========================================================================"
echo ""
echo "Erstellt: $CREATED Issues"
echo "Fehler:   $FAILED Issues"
echo "Gesamt:   38 Issues"
echo ""
echo "Kategorien:"
echo "  SOLID (SRP):     3 Issues (Kritisch)"
echo "  SOLID (OCP):     3 Issues"
echo "  SOLID (LSP):     4 Issues"
echo "  SOLID (ISP):     1 Issue"
echo "  SOLID (DIP):     6 Issues"
echo "  DRY:             4 Issues (1 Quick Win)"
echo "  KISS:            4 Issues"
echo "  YAGNI:           3 Issues"
echo "  LoD:             3 Issues"
echo "  SoC:             3 Issues"
echo "  CoC:             4 Issues"
echo ""
echo "Quick Wins (starten Sie hier):"
echo "  - Issue #18: IsoBmffParser Context (2-3 Tage)"
echo "  - Issue #19: GpsConverter Decoder (1 Tag)"
echo "  - Issue #7-10: LSP Fixes (je 4 Stunden)"
echo ""
echo "Nächste Schritte:"
echo "  1. Issues in GitHub überprüfen"
echo "  2. Quick Wins zuerst angehen"
echo "  3. Dann God Classes (Issues #1, #2, #3)"
echo "  4. Fortschritt in RE_AUDIT_REPORT.md tracken"
echo ""
echo "========================================================================"

if [ $FAILED -gt 0 ]; then
    echo "⚠️  Einige Issues ($FAILED) konnten nicht erstellt werden."
    echo "    Aber $CREATED Issues wurden erfolgreich erstellt."
    echo "    Prüfen Sie die Fehlermeldungen oben für Details."
    echo ""
    echo "✅ Skript hat alle 38 Issues verarbeitet!"
else
    echo "✅ Alle 38 Issues erfolgreich erstellt!"
fi

# Erfolgreich beenden, auch wenn einige Issues fehlgeschlagen sind
exit 0
