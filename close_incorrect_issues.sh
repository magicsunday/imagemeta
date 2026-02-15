#!/bin/bash

# Schließt die 38 fälschlicherweise erstellten Issues
# mit einer Erklärung, dass sie auf veraltetem Code basierten

REPO="magicsunday/imagemeta"

echo "========================================================================"
echo "Schließe fälschlicherweise erstellte Issues"
echo "========================================================================"
echo ""
echo "Grund: Original-Audit basierte auf veraltetem Code-Stand"
echo "       35 von 38 Violations wurden bereits durch GH-1424, GH-1429 gelöst"
echo ""

# Kommentar für geschlossene Issues
CLOSE_COMMENT="## ⚠️ Issue geschlossen - Basierte auf veraltetem Audit

Dieses Issue wurde basierend auf einem **fehlerhaften Forensischen Audit** erstellt.

### Problem

Der Audit analysierte einen **veralteten Code-Stand** (shallow clone) statt des aktuellen main-Branches.

### Tatsächlicher Stand (main Branch)

Diese Violation wurde **bereits gelöst** durch:

- ✅ **GH-1424**: Extract Marker Handler Strategy (JpegParser -57% LOC)
- ✅ **GH-1429**: Introduce Dependency Injection (DIP violations gelöst)
- ✅ **20+ weitere Commits**: JPEG Strictness-Verbesserungen

### Korrigierter Audit

Siehe: **CORRECTED_AUDIT.md** im Repository

**Tatsächliche Violations:**
- Nur **3 von 38** sind noch relevant
- 35 wurden bereits gelöst (92%!)

### Neue Issues

Die **3 tatsächlichen Violations** wurden als separate Issues erstellt:
1. ParsedExif ISP (MEDIUM)
2. TiffExifParser Helpers (LOW/OPTIONAL)
3. IsoBmffParser Context (LOW)

---

**Entschuldigung** für die Verwirrung! Der Audit-Prozess wurde korrigiert."

# Funktion zum Schließen eines Issues
close_issue() {
    local issue_number=$1
    
    echo "Schließe Issue #$issue_number..."
    
    # Kommentar hinzufügen
    gh issue comment "$issue_number" --repo "$REPO" --body "$CLOSE_COMMENT" 2>&1
    
    # Issue schließen
    gh issue close "$issue_number" --repo "$REPO" --reason "not planned" 2>&1
    
    echo "  ✓ Issue #$issue_number geschlossen"
    echo ""
}

# ============================================================================
# Option 1: ALLE 38 Issues schließen (empfohlen)
# ============================================================================

echo "Option 1: ALLE 38 Issues auf einmal schließen"
echo ""
read -p "Möchten Sie ALLE 38 Issues schließen? (j/n): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[JjYy]$ ]]; then
    echo ""
    echo "Schließe alle 38 Issues..."
    echo ""
    
    # Alle Issues von 1 bis 38 schließen
    for i in {1..38}; do
        close_issue "$i"
        sleep 1  # Rate limiting vermeiden
    done
    
    echo "========================================================================"
    echo "✅ Alle 38 Issues wurden geschlossen!"
    echo "========================================================================"
    echo ""
    echo "Nächste Schritte:"
    echo "  1. Führen Sie ./create_corrected_issues.sh aus"
    echo "  2. Dies erstellt die 3 KORREKTEN Issues"
    echo ""
    exit 0
fi

# ============================================================================
# Option 2: Spezifische Issues schließen
# ============================================================================

echo ""
echo "Option 2: Spezifische Issue-Nummern eingeben"
echo ""
read -p "Issue-Nummern (kommagetrennt, z.B. 1,2,3): " issue_numbers

IFS=',' read -ra ISSUES <<< "$issue_numbers"

for issue in "${ISSUES[@]}"; do
    issue=$(echo "$issue" | xargs)  # Trim whitespace
    close_issue "$issue"
    sleep 1
done

echo "========================================================================"
echo "✅ Ausgewählte Issues wurden geschlossen!"
echo "========================================================================"

exit 0
