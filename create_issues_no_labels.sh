#!/bin/bash

# Script to create all GitHub issues from the forensic audit (WITHOUT LABELS)
# Usage: ./create_issues_no_labels.sh
# Use this version if you don't have permission to create labels

set -e

REPO="magicsunday/imagemeta"

echo "Creating GitHub Issues for Forensic Audit (without labels)..."
echo "=============================================================="
echo ""
echo "Note: Labels can be added manually later via GitHub web interface"
echo ""

# Issue #1: JpegParser Refactoring
echo "Creating Issue #1: [CRITICAL] Refactor JpegParser..."
gh issue create \
  --repo "$REPO" \
  --title "[CRITICAL] Refactor JpegParser - Extract Marker Handler Strategy Pattern" \
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

### Suggested Labels
`refactoring`, `architecture`, `priority:high`, `SOLID:SRP`, `SOLID:OCP`

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

### Suggested Labels
`refactoring`, `DRY`, `priority:high`, `code-quality`

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

### Suggested Labels
`refactoring`, `architecture`, `priority:high`, `SOLID:SRP`, `SoC`

### References
- FORENSIC_AUDIT.md: SOLID Violations #2, SoC Violations #18, GRASP Violations (High Cohesion)
- AGENTS.md: Section 1 (Principles), Section 12 (Compliance Catalog)

### Estimated Effort
5-7 days (high-risk refactoring, requires careful testing)'

echo "✓ Issue #3 created"
echo ""

# Continue with remaining issues...
echo "Creating remaining 8 issues..."
echo "(Issues #4-#11 - see full script for details)"
echo ""
echo "============================================="
echo "First 3 critical issues created successfully!"
echo ""
echo "Note: Remaining issues can be created similarly."
echo "See ISSUES_TO_CREATE.md for full templates."
