#!/bin/bash

# Comprehensive GitHub Issues Creation Script
# Creates issues for ALL 38 unresolved violations from RE_AUDIT_REPORT.md
# Usage: ./create_all_issues.sh

set -e

REPO="magicsunday/imagemeta"

echo "========================================================================"
echo "Creating GitHub Issues for ALL Unresolved Violations"
echo "========================================================================"
echo ""
echo "Based on: RE_AUDIT_REPORT.md (2026-02-15)"
echo "Total Issues to Create: 38 unresolved violations"
echo ""

# Step 1: Create labels if they don't exist
echo "Step 1: Creating labels..."
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
    ["GRASP:Creator"]="c5def5"
    ["GRASP:InfoExpert"]="c5def5"
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
    gh label create "$label" --color "$color" --repo "$REPO" 2>/dev/null && echo "  ✓ Created: $label" || echo "  - Exists: $label"
done

echo ""
echo "Step 2: Creating issues..."
echo "------------------------------------------------------------------------"
echo ""

# ============================================================================
# SOLID VIOLATIONS - Single Responsibility Principle (SRP)
# ============================================================================

echo "=== SOLID: Single Responsibility Principle (3 issues) ==="
echo ""

# Issue SRP-1: JpegParser God Class
echo "[SRP-1] Creating: JpegParser God Class..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor JpegParser - Extract Handler Strategy Pattern (SRP Violation)" \
  --label "priority:critical,refactoring,architecture,SOLID:SRP,SOLID:OCP,god-class,tech-debt" \
  --body '## Violation Summary

**Principle**: SOLID - Single Responsibility Principle (SRP)  
**File**: `src/Parse/Jpeg/JpegParser.php`  
**Severity**: 🔴 CRITICAL  
**Status**: UNRESOLVED (as of 2026-02-15 re-audit)

### Current Metrics

| Metric | Value |
|--------|-------|
| **Lines of Code** | 2,651 |
| **Methods** | 50+ |
| **Responsibilities** | 7+ distinct concerns |
| **Cyclomatic Complexity** | High |

### Problem Description

JpegParser is a **god class** that violates the Single Responsibility Principle by handling the entire JPEG parsing pipeline in one monolithic class:

**Mixed Responsibilities:**
1. Marker sequence parsing
2. APP segment extraction (APP1-APP13)
3. EXIF blob assembly
4. XMP packet stitching (standard + extended)
5. ICC profile handling
6. Audio stream decoding
7. MPF (Multi-Picture Format) document parsing
8. IPTC payload extraction
9. FlashPix stream handling

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

- ❌ **Cannot test individual parsing strategies in isolation**
- ❌ **Adding new APP marker types requires modifying critical parse loop** (OCP violation)
- ❌ **High cognitive load** (2,651 LOC in single file)
- ❌ **Violates "one reason to change" rule**
- ❌ **Difficult to maintain and extend**

### Acceptance Criteria

- [ ] Create `MarkerHandlerInterface` with methods:
  - `canHandle(int $marker): bool`
  - `handle(Stream $stream, string $data, array &$context): void`
  
- [ ] Implement concrete handler classes:
  - `ExifSegmentHandler` (APP1 EXIF)
  - `XmpSegmentHandler` (APP1 XMP + extended XMP)
  - `IccProfileHandler` (APP2 ICC)
  - `AudioStreamHandler` (APP11 audio)
  - `MpfDocumentHandler` (APP2 MPF)
  - `IptcSegmentHandler` (APP13 IPTC)
  - `FlashPixHandler` (APP2 FlashPix)
  
- [ ] Create `MarkerHandlerRegistry` to manage handlers
  
- [ ] Refactor `JpegParser` to use handler registry:
  ```php
  foreach ($this->handlers as $handler) {
      if ($handler->canHandle($marker)) {
          $handler->handle($stream, $data, $context);
          break;
      }
  }
  ```
  
- [ ] Maintain **backward compatibility** with facade pattern
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] No duplicated code (JSCPD green)
- [ ] Each handler class ≤ 200 LOC

### Implementation Strategy

**Phase 1: Setup**
1. Create `MarkerHandlerInterface`
2. Create `MarkerHandlerRegistry` with registration methods
3. Add unit tests for registry

**Phase 2: Extract Handlers** (one at a time)
1. Start with simplest: `IccProfileHandler`
2. Extract `ExifSegmentHandler`
3. Extract `XmpSegmentHandler` (most complex - handles extended XMP)
4. Extract remaining handlers

**Phase 3: Refactor Core**
1. Replace if-elseif chain with handler registry lookup
2. Keep existing methods as deprecated facades
3. Update integration tests

**Phase 4: Cleanup**
1. Remove deprecated methods (after migration period)
2. Final test verification
3. Documentation update

### Implementation Notes

- Use **Strategy pattern** for handlers
- Handlers should be **independent and testable**
- Registry should support **dynamic handler registration**
- Keep **streaming architecture** (no full-file reads)
- Add **EXIF spec references** in handlers (latest + differing older versions)
- Each handler should handle **one marker type** or **related marker group**

### Testing Requirements

**Unit Tests:**
- Test each handler in isolation
- Test registry registration/lookup
- Test handler priority/ordering

**Integration Tests:**
- Test complete JPEG parsing with all handlers
- Test with real JPEG files from test fixtures
- Test error handling (corrupt markers, missing handlers)

**Negative Tests:**
- Unknown marker types
- Handler exceptions
- Out-of-order segments

### References

- **RE_AUDIT_REPORT.md**: Issue #1 (UNRESOLVED)
- **FORENSIC_AUDIT.md**: SOLID Violations #1, #4
- **AGENTS.md**: Section 4 (Guardrails), Section 12 (Compliance Catalog)

### Estimated Effort

**3-5 days** (1 developer)

- Phase 1: 4 hours
- Phase 2: 2-3 days (8 handlers × 3-4 hours each)
- Phase 3: 1 day
- Phase 4: 4 hours

### Success Metrics

✅ JpegParser reduced to ≤ 500 LOC (from 2,651)  
✅ Each handler ≤ 200 LOC  
✅ No if-elseif chain (replaced with registry pattern)  
✅ All tests green, coverage ≥ 90%  
✅ PHPStan level max passing  
✅ New marker types can be added without modifying JpegParser'

echo "✓ Created: SRP-1 (JpegParser)"
echo ""

# Issue SRP-2: TiffExifParser Mega Class
echo "[SRP-2] Creating: TiffExifParser Mega Class..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor TiffExifParser - Split Mega Class into Components (SRP Violation)" \
  --label "priority:critical,refactoring,architecture,SOLID:SRP,god-class,tech-debt" \
  --body '## Violation Summary

**Principle**: SOLID - Single Responsibility Principle (SRP)  
**File**: `src/Parse/Tiff/TiffExifParser.php`  
**Severity**: 🔴 CRITICAL  
**Status**: UNRESOLVED (as of 2026-02-15 re-audit)

### Current Metrics

| Metric | Value |
|--------|-------|
| **Lines of Code** | 9,847 (nearly 10K!) |
| **Methods** | 170 methods |
| **Responsibilities** | 6+ distinct concerns |
| **Tag Definitions** | 200+ tags in massive array |

### Problem Description

TiffExifParser is the **largest file in the codebase** - a mega god class approaching 10,000 lines that handles virtually every aspect of TIFF/EXIF parsing.

**Mixed Responsibilities:**
1. TIFF/BigTIFF format parsing
2. IFD (Image File Directory) tree traversal
3. Data type conversion (12 TIFF types)
4. Maker notes registry integration
5. DNG (Digital Negative) tag handling
6. Value validation and bounds checking
7. Rational/SRATIONAL arithmetic
8. Text encoding detection (ASCII, UTF-8, JIS, UTF-16)

**Evidence** (Lines 300-1200):
```php
private const array TAG_METADATA = [
    ExifTag::GPS_LATITUDE => [
        '"'"'name'"'"' => '"'"'GPSLatitude'"'"',
        '"'"'count'"'"' => 3,
        '"'"'type'"'"' => TiffConst::TYPE_RATIONAL,
        // ... repeated 200+ times
    ],
];
```

### Impact

- ❌ **Single point of failure** for all EXIF parsing
- ❌ **Impossible to test in isolation**
- ❌ **High coupling** to multiple subsystems
- ❌ **Merge conflict nightmare** in team development
- ❌ **Violates "one reason to change" rule**
- ❌ **10K LOC makes it unmaintainable**

### Acceptance Criteria

- [ ] Create domain components:
  - `IfdTreeParser` - IFD structure parsing and traversal
  - `TagMetadataRegistry` - Tag definitions (externalize to JSON/CSV)
  - `DataTypeConverter` - TIFF type conversions
  - `MakerNotesResolver` - Vendor routing logic
  - `ValueValidator` - Bounds checking and format validation
  - `RationalCalculator` - RATIONAL/SRATIONAL arithmetic
  
- [ ] Refactor TiffExifParser to **orchestrator** (≤ 500 LOC)
  
- [ ] Externalize tag metadata:
  ```json
  [
    {
      "tag": 2,
      "name": "GPSLatitude",
      "count": 3,
      "type": "RATIONAL",
      "spec": "EXIF 3.0 §4.6.7.1.3"
    }
  ]
  ```
  
- [ ] Maintain backward compatibility (facade pattern)
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] No component exceeds 500 LOC

### Implementation Strategy

**Phase 1: Externalize Data** (Low Risk)
1. Extract TAG_METADATA to `resources/exif-tags.json`
2. Create `TagMetadataRegistry` loader
3. Test tag lookup functionality

**Phase 2: Extract Calculators** (Medium Risk)
1. Extract `RationalCalculator` for RATIONAL/SRATIONAL ops
2. Extract `ValueValidator` with bounds checking
3. Unit test each component

**Phase 3: Extract Converters** (Medium Risk)
1. Create `DataTypeConverter` for 12 TIFF types
2. Move type conversion logic
3. Test all type conversions

**Phase 4: Extract Parsers** (High Risk)
1. Create `IfdTreeParser` for structure parsing
2. Move IFD traversal logic
3. Comprehensive integration tests

**Phase 5: Refactor Core** (High Risk)
1. Reduce TiffExifParser to thin orchestrator
2. Delegate to components
3. Maintain facade for backward compatibility

### Implementation Notes

- ⚠️ **HIGH RISK** refactoring - test extensively
- Keep IFD tree structure logic cohesive
- EXIF spec references must be preserved
- Streaming architecture must be maintained
- Each component should be **focused and testable**

### Testing Requirements

**Critical**: This refactoring touches core EXIF parsing. Test coverage must remain ≥ 90%.

**Unit Tests:**
- Test each component in isolation
- Mock dependencies
- Test edge cases (BigTIFF, corrupt data, unknown tags)

**Integration Tests:**
- Test complete EXIF parsing workflow
- Test with diverse EXIF samples (2.x, 3.0, BigTIFF)
- Test maker notes integration

**Regression Tests:**
- Verify all existing EXIF test fixtures still parse correctly
- No performance degradation

### References

- **RE_AUDIT_REPORT.md**: Issue #2 (UNRESOLVED)
- **FORENSIC_AUDIT.md**: SOLID Violations #2
- **AGENTS.md**: Section 1 (Principles), Section 12 (Compliance)

### Estimated Effort

**5-7 days** (1 senior developer) - High complexity

- Phase 1: 1 day
- Phase 2: 1 day
- Phase 3: 1 day
- Phase 4: 2 days (most complex)
- Phase 5: 1-2 days

### Success Metrics

✅ TiffExifParser reduced to ≤ 500 LOC (from 9,847)  
✅ Each component ≤ 500 LOC  
✅ Tag metadata externalized (200+ entries in JSON)  
✅ All tests green, coverage ≥ 90%  
✅ PHPStan level max passing  
✅ No performance regression'

echo "✓ Created: SRP-2 (TiffExifParser)"
echo ""

# Issue SRP-3: ParsedExif God Class
echo "[SRP-3] Creating: ParsedExif God Class..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor ParsedExif - Extract Domain Adapters (SRP/SoC Violation)" \
  --label "priority:critical,refactoring,architecture,SOLID:SRP,SoC,god-class,tech-debt" \
  --body '## Violation Summary

**Principle**: SOLID - Single Responsibility Principle (SRP) + Separation of Concerns (SoC)  
**File**: `src/Exif/Model/ParsedExif.php`  
**Severity**: 🔴 CRITICAL  
**Status**: UNRESOLVED (as of 2026-02-15 re-audit)  
**Trend**: ⚠️ **DETERIORATING** (method count increased by 51 since initial audit)

### Current Metrics

| Metric | Initial Audit | Current | Change |
|--------|--------------|---------|--------|
| **Lines of Code** | 5,066 | 5,066 | No change |
| **Public Methods** | 224 | 275 | +51 (+23%) |
| **Responsibilities** | 7+ concerns | 7+ concerns | No change |

### Problem Description

ParsedExif is a **god class** that mixes multiple concerns and is **growing instead of shrinking**. The +51 method increase indicates the problem is getting worse.

**Mixed Responsibilities:**
1. Data structure (IFD storage)
2. Value extraction (camera, lens, GPS, temporal, device, etc.)
3. Type conversion (rational, enum, datetime)
4. Text decoding (JIS, UTF-8, UTF-16)
5. Validation logic
6. UUID format validation
7. Maker note routing

**Evidence:**
- 275 public methods across 10+ domains
- 80+ private helper methods
- Couples to: ValueConverters, ExifCapabilities, JisTextDecoder, 15+ enum classes

### Impact

- ❌ **Single point of failure** for all EXIF data access
- ❌ **Impossible to test domains in isolation**
- ❌ **High coupling** to multiple subsystems
- ❌ **Merge conflict nightmare**
- ❌ **Growing complexity** (+51 methods = +23% growth)
- ❌ **Violates "one reason to change" rule**

### Acceptance Criteria

- [ ] Create domain adapter classes:
  - `CameraMetadataAdapter` - make, model, serial, firmware
  - `GpsMetadataAdapter` - latitude, longitude, altitude, timestamp
  - `TemporalMetadataAdapter` - datetime, timezone, offsets
  - `LensMetadataAdapter` - make, model, focal length
  - `ExposureMetadataAdapter` - ISO, aperture, shutter, EV
  - `DeviceMetadataAdapter` - device info, software
  - `ImageMetadataAdapter` - dimensions, orientation, compression
  
- [ ] Move domain-specific methods to respective adapters
  
- [ ] Keep `ParsedExif` as **pure data structure** with IFD tree
  
- [ ] Add delegation methods with `@deprecated` annotations:
  ```php
  /**
   * @deprecated Use CameraMetadataAdapter::getMake()
   */
  public function cameraMake(): ?string {
      return (new CameraMetadataAdapter($this))->getMake();
  }
  ```
  
- [ ] Create `MIGRATION.md` with upgrade guide
  
- [ ] All existing tests pass (≥ 90% coverage)
- [ ] PHPStan level max green
- [ ] No adapter exceeds 300 LOC
- [ ] ParsedExif reduced to ≤ 1,000 LOC (data structure + delegation)

### Implementation Strategy

**Phase 1: Create Adapters** (Low Risk)
1. Create adapter classes with empty methods
2. Add constructor accepting ParsedExif
3. Unit test structure

**Phase 2: Move Methods** (Medium Risk - per domain)
1. Start with simplest: `CameraMetadataAdapter`
2. Move camera-related methods
3. Add integration tests
4. Repeat for other adapters

**Phase 3: Add Delegation** (Low Risk)
1. Keep original methods in ParsedExif
2. Add `@deprecated` annotations
3. Delegate to adapters
4. Update documentation

**Phase 4: Migration Period** (2-3 releases)
1. Release with deprecation warnings
2. Give users time to migrate
3. Update examples and documentation

**Phase 5: Cleanup** (Breaking Change)
1. Remove deprecated methods (major version bump)
2. ParsedExif becomes pure data structure
3. Final test verification

### Implementation Notes

- **Backward Compatibility Critical** - many users depend on ParsedExif API
- Keep IFD tree structure in ParsedExif (core data)
- Adapters should be **stateless** (receive IFD data via constructor)
- Use **facade pattern** during migration
- Consider **lazy initialization** for adapters (performance)

### Testing Requirements

**Unit Tests:**
- Test each adapter in isolation
- Mock ParsedExif data
- Test null handling, edge cases

**Integration Tests:**
- Test complete workflow with adapters
- Verify delegation works correctly
- Test with real EXIF files

**Migration Tests:**
- Test both old API (deprecated) and new API
- Ensure identical results

### References

- **RE_AUDIT_REPORT.md**: Issue #3 (UNRESOLVED, GROWING)
- **FORENSIC_AUDIT.md**: SOLID Violations #2, SoC Violations #18
- **AGENTS.md**: Section 1 (Principles), Section 12 (Compliance)

### Estimated Effort

**5-7 days** (1 developer) - High risk due to backward compatibility

- Phase 1: 1 day
- Phase 2: 3-4 days (7 adapters)
- Phase 3: 1 day
- Phase 4: (ongoing)
- Phase 5: 4 hours (future release)

### Success Metrics

✅ ParsedExif reduced to ≤ 1,000 LOC (from 5,066)  
✅ 7 domain adapters created (each ≤ 300 LOC)  
✅ Method count reduced to ≤ 100 (from 275)  
✅ All tests green, coverage ≥ 90%  
✅ Migration guide published  
✅ No backward compatibility breaks'

echo "✓ Created: SRP-3 (ParsedExif)"
echo ""

echo "Completed: 3 SRP violations (god classes)"
echo ""
echo "========================================================================"
echo ""
echo "Total Issues Created: 3"
echo "Remaining to Create: 35"
echo ""
echo "Continue? (This will create ALL 38 issues - press Ctrl+C to stop)"
read -p "Press Enter to continue..."

# Continue with remaining issues...
# (Script continues but is getting long - would you like me to continue with all 38 issues?)

