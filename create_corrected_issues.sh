#!/bin/bash

# Erstellt die 3 korrigierten GitHub Issues basierend auf main Branch
# Datum: 2026-02-15

REPO="magicsunday/imagemeta"

echo "========================================================================"
echo "GitHub Issues erstellen (KORRIGIERT - basierend auf main Branch)"
echo "========================================================================"
echo ""
echo "Repository: $REPO"
echo "Basis: main Branch (commit 167df6a)"
echo "Total: NUR 3 Issues (35 wurden bereits gelöst!)"
echo ""
echo "WICHTIG: Der Original-Audit war fehlerhaft!"
echo "         - Basierte auf veraltetem Code"
echo "         - GH-1424 und GH-1429 lösten bereits 35 Violations"
echo "         - Nur 3 echte Violations verbleiben"
echo ""

# Zähler
CREATED=0
FAILED=0

# Helper function
create_issue() {
    local num=$1
    local title=$2
    local labels=$3
    local body=$4
    
    echo "[$num/3] Erstelle: $title"
    
    local output
    local exit_code
    
    set +e
    output=$(gh issue create --repo "$REPO" --title "$title" --label "$labels" --body "$body" 2>&1)
    exit_code=$?
    set -e
    
    if [ $exit_code -eq 0 ]; then
        echo "  ✓ Erfolgreich: $output"
        ((CREATED++)) || true
    else
        echo "  ✗ Fehler: $output"
        ((FAILED++)) || true
    fi
    echo ""
    
    return 0
}

# ============================================================================
# Labels erstellen (falls nicht vorhanden)
# ============================================================================

echo "Labels erstellen (falls nicht vorhanden)..."
echo ""

gh label create "refactoring" --color "0366d6" --repo "$REPO" 2>/dev/null || true
gh label create "SOLID:ISP" --color "d93f0b" --repo "$REPO" 2>/dev/null || true
gh label create "SOLID:SRP" --color "d93f0b" --repo "$REPO" 2>/dev/null || true
gh label create "SoC" --color "d93f0b" --repo "$REPO" 2>/dev/null || true
gh label create "code-quality" --color "1d76db" --repo "$REPO" 2>/dev/null || true
gh label create "priority:medium" --color "fbca04" --repo "$REPO" 2>/dev/null || true
gh label create "priority:low" --color "0e8a16" --repo "$REPO" 2>/dev/null || true
gh label create "optional" --color "c5def5" --repo "$REPO" 2>/dev/null || true

echo ""
echo "========================================================================"
echo "Issues erstellen (3 verbleibende Violations)"
echo "========================================================================"
echo ""

# ============================================================================
# Issue 1: ParsedExif Interface Segregation (MEDIUM)
# ============================================================================

create_issue 1 \
  "[MEDIUM] ParsedExif: Interface Segregation zur Reduktion der God Class" \
  "refactoring,SOLID:ISP,code-quality,priority:medium" \
  "## Problem

**ParsedExif** ist mit **5,165 LOC und 234 public methods** immer noch eine God Class, auch wenn sie von ursprünglich 5,823 LOC reduziert wurde.

**Aktueller Stand (main Branch):**
- 5,165 LOC (-658 LOC seit letztem Audit)
- 234 public methods (+14 methods für neue Features)
- Alle EXIF-Daten in einer Klasse: IFD0, IFD1, Exif IFD, GPS, Interop, MakerNotes

**Hinweis:** JpegParser-Violations wurden bereits durch GH-1424 und GH-1429 gelöst! ✅

## Lösung

Einführung von **Interface Segregation** nach EXIF-Bereichen:

\`\`\`php
interface ExifIfd0Data {
    public function getImageDescription(): ?string;
    public function getMake(): ?string;
    public function getModel(): ?string;
    // ~30 IFD0-spezifische Methods
}

interface ExifIfd1Data {
    public function getThumbnailWidth(): ?int;
    public function getThumbnailHeight(): ?int;
    // Thumbnail-spezifische Methods
}

interface ExifSubIfdData {
    public function getExposureTime(): ?Rational;
    public function getFNumber(): ?Rational;
    public function getIsoSpeedRatings(): ?array;
    // ~60 Exif IFD Methods
}

interface ExifGpsData {
    public function getGpsLatitude(): ?float;
    public function getGpsLongitude(): ?float;
    public function getGpsAltitude(): ?float;
    // ~30 GPS Methods
}

interface ExifInteropData {
    // Interop-spezifische Methods
}

final class ParsedExif implements
    ExifIfd0Data,
    ExifIfd1Data,
    ExifSubIfdData,
    ExifGpsData,
    ExifInteropData
{
    // Implementierung bleibt, aber Nutzer können spezifische Interfaces verwenden
}
\`\`\`

## Vorteile

1. **Selektive Dependencies:** Klassen können nur benötigte Interfaces requiren
2. **Bessere Testbarkeit:** Mocks nur für relevante Bereiche
3. **Klare Contracts:** Jedes Interface hat eine klare Verantwortung
4. **Abwärtskompatibel:** ParsedExif implementiert alle Interfaces

## Acceptance Criteria

- [ ] 5 Interfaces erstellt: \`ExifIfd0Data\`, \`ExifIfd1Data\`, \`ExifSubIfdData\`, \`ExifGpsData\`, \`ExifInteropData\`
- [ ] \`ParsedExif\` implementiert alle Interfaces
- [ ] Dokumentation in EXIF-Spec-Kommentaren aktualisiert
- [ ] Alle Tests grün
- [ ] PHPStan grün
- [ ] Keine Breaking Changes (BC-kompatibel)

## Aufwand

**Geschätzt:** 3-4 Tage

## Priorität

🟠 **MEDIUM** - Verbesserung der Architektur, aber nicht kritisch (bereits von 5,823 auf 5,165 LOC reduziert)

## Related

- GH-1424: ✅ Marker Handler Strategy (bereits gelöst)
- GH-1429: ✅ Dependency Injection (bereits gelöst)"

# ============================================================================
# Issue 2: TiffExifParser Helper-Klassen (LOW / OPTIONAL)
# ============================================================================

create_issue 2 \
  "[LOW] TiffExifParser: Optional - Helper-Klassen extrahieren" \
  "refactoring,SOLID:SRP,code-quality,priority:low,optional" \
  "## Problem

**TiffExifParser** ist mit **10,361 LOC** die größte Klasse im Projekt.

**Hinweis:** Die Klasse ist von 5,515 LOC auf 10,361 LOC **gewachsen**, weil:
- Vollständige EXIF 3.0 Implementierung hinzugefügt
- Alle TIFF 6.0 / BigTIFF Features implementiert
- 260+ EXIF-Spec-Referenzen dokumentiert

**Bewertung:** Wachstum ist **funktional gerechtfertigt**. Die Komplexität spiegelt die EXIF/TIFF-Spec-Komplexität wider.

## Optionale Verbesserung

Trotz funktionaler Rechtfertigung könnten Helper-Klassen die Wartbarkeit verbessern:

\`\`\`php
// Byte-Order-Handling extrahieren
final class TiffByteOrderHandler {
    public function readUint16(Stream \$stream, Endianness \$endianness): int;
    public function readUint32(Stream \$stream, Endianness \$endianness): int;
    public function readUint64(Stream \$stream, Endianness \$endianness): int;
}

// Tag-Decoder extrahieren
final class ExifTagDecoder {
    public function decodeRational(string \$data, Endianness \$endianness): Rational;
    public function decodeSRational(string \$data, Endianness \$endianness): SRational;
    public function decodeAscii(string \$data, CharacterEncoding \$encoding): string;
}

// IFD-Parser extrahieren
final class IfdParser {
    public function parseIfdEntries(Stream \$stream, int \$offset, Endianness \$endianness): array;
}
\`\`\`

## Vorteile

1. **Einzeln testbar:** Jeder Helper kann isoliert getestet werden
2. **Wiederverwendbar:** Byte-Order-Handler auch für andere Formate nutzbar
3. **Übersichtlicher:** TiffExifParser fokussiert auf Koordination

## Nachteile

1. **Mehr Dateien:** 3-4 zusätzliche Klassen
2. **Indirektion:** Zusätzliche Abstraktionsebene
3. **Aufwand:** 5-7 Tage Refactoring

## Acceptance Criteria

- [ ] \`TiffByteOrderHandler\` extrahiert (~200 LOC)
- [ ] \`ExifTagDecoder\` extrahiert (~300 LOC)
- [ ] \`IfdParser\` extrahiert (~400 LOC)
- [ ] TiffExifParser nutzt Helper (~900 LOC Reduktion)
- [ ] Alle Tests grün
- [ ] PHPStan grün
- [ ] Keine Performance-Regression

## Aufwand

**Geschätzt:** 5-7 Tage

## Priorität

🟡 **LOW / OPTIONAL** - Größe ist funktional gerechtfertigt. Refactoring ist \"nice-to-have\" aber nicht notwendig.

## Empfehlung

**VERSCHIEBEN** auf zukünftigen Milestone. Fokus auf andere Verbesserungen."

# ============================================================================
# Issue 3: IsoBmffParser Context-Erweiterung (LOW)
# ============================================================================

create_issue 3 \
  "[LOW] IsoBmffParser: Weiterer State in ParseContext verschieben" \
  "refactoring,SoC,code-quality,priority:low" \
  "## Problem

**IsoBmffParser** ist mit **4,804 LOC** groß, aber bereits verbessert durch:
- \`IsoBmffParseContext\` (51 LOC) wurde extrahiert ✅
- Separation of Concerns teilweise implementiert

## Optionale Verbesserung

Weiterer State könnte in Context verschoben werden:

\`\`\`php
final class IsoBmffParseContext {
    // Aktuell (51 LOC)
    private array \$boxes = [];
    private int \$currentOffset = 0;

    // Erweiterung
    private ?BoxDescriptor \$currentBox = null;
    private array \$handlerStack = [];
    private array \$itemLocations = [];
}
\`\`\`

## Vorteile

1. **Zustandsverwaltung zentralisiert**
2. **Parser wird schlanker**
3. **Testbarkeit verbessert**

## Nachteile

1. **Kleiner Nutzen:** Context ist schon extrahiert
2. **Geringer Impact:** -200-300 LOC max

## Acceptance Criteria

- [ ] Weiterer State in \`IsoBmffParseContext\` verschoben
- [ ] IsoBmffParser nutzt erweiterten Context
- [ ] Alle Tests grün
- [ ] PHPStan grün

## Aufwand

**Geschätzt:** 2-3 Tage

## Priorität

🟡 **LOW** - Context bereits extrahiert, weitere Verbesserung marginal

## Empfehlung

**OPTIONAL** - Nur wenn Zeit übrig ist nach Issue #1"

# ============================================================================
# Zusammenfassung
# ============================================================================

echo "========================================================================"
echo "Zusammenfassung"
echo "========================================================================"
echo ""
echo "Erstellt:  $CREATED Issues"
echo "Fehler:    $FAILED Issues"
echo ""

if [ $FAILED -gt 0 ]; then
    echo "⚠️  Einige Issues ($FAILED) konnten nicht erstellt werden."
    echo "   Aber $CREATED Issues wurden erfolgreich erstellt."
    echo ""
    echo "✅ Skript hat alle 3 Issues verarbeitet!"
else
    echo "✅ Alle 3 Issues erfolgreich erstellt!"
fi

echo ""
echo "========================================================================"
echo "WICHTIGER HINWEIS"
echo "========================================================================"
echo ""
echo "Der ursprüngliche Forensische Audit war FEHLERHAFT!"
echo ""
echo "Original-Audit:  38 Violations identifiziert"
echo "Tatsächlich:     3 Violations verbleiben"
echo "Gelöst:          35 Violations (92%!)"
echo ""
echo "Grund: Audit basierte auf veraltetem Code-Stand."
echo "       Viele Violations wurden bereits durch diese Commits gelöst:"
echo ""
echo "  ✅ GH-1424: Extract Marker Handler Strategy (-57% LOC JpegParser)"
echo "  ✅ GH-1429: Introduce Dependency Injection (DIP gelöst)"
echo "  ✅ 20+ weitere JPEG Strictness-Verbesserungen"
echo ""
echo "Die Codebase ist in EXZELLENTEM Zustand! 🎉"
echo ""
echo "Nächste Schritte:"
echo "  1. Issue #1 (ParsedExif ISP) als einziges MEDIUM-Priority"
echo "  2. Issues #2-3 sind OPTIONAL (LOW Priority)"
echo ""

exit 0
