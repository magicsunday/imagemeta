# Anleitung: GitHub Issues für alle Violations erstellen

## Übersicht

Basierend auf dem Re-Audit wurden **38 ungelöste Violations** identifiziert. Dieses Dokument erklärt, wie Sie für jede Violation ein detailliertes GitHub Issue erstellen.

## Verfügbare Methoden

### Methode 1: Python-Skript (Empfohlen) 🐍

**Vorteile:**
- ✅ Erstellt alle 38 Issues automatisch
- ✅ Liest strukturierte Daten aus YAML
- ✅ Zeigt Fortschritt und Fehler an

**Voraussetzungen:**
```bash
# Python 3 und PyYAML installieren
pip3 install pyyaml

# GitHub CLI authentifizieren
gh auth login --web
```

**Ausführung:**
```bash
cd /home/runner/work/imagemeta/imagemeta
python3 create_issues_from_yaml.py
```

**Was passiert:**
```
========================================
Creating 38 GitHub Issues
Repository: magicsunday/imagemeta
========================================

[1/38] Creating SRP-1: [CRITICAL] Refactor JpegParser...
  ✓ Created: https://github.com/magicsunday/imagemeta/issues/1

[2/38] Creating SRP-2: [CRITICAL] Refactor TiffExifParser...
  ✓ Created: https://github.com/magicsunday/imagemeta/issues/2

...

========================================
Summary:
  Total:   38
  Created: 38
  Failed:  0
========================================
```

---

### Methode 2: Bash-Skript (Alternativ) 🔧

**Hinweis:** Das Bash-Skript ist unvollständig (nur 3 Issues). Verwenden Sie die Python-Methode für alle 38 Issues.

```bash
./create_all_issues.sh
```

---

### Methode 3: Manuell aus YAML (Für einzelne Issues) 📝

Öffnen Sie `ALL_ISSUES.yaml` und erstellen Sie Issues einzeln:

```bash
# Beispiel für ein einzelnes Issue
gh issue create \
  --repo magicsunday/imagemeta \
  --title "[CRITICAL] Refactor JpegParser - Extract Handler Strategy Pattern" \
  --label "priority:critical,refactoring,architecture,SOLID:SRP,god-class" \
  --body "$(yq '.issues[0].body' ALL_ISSUES.yaml)"
```

---

## Issue-Struktur

Die YAML-Datei definiert 38 Issues in 10 Kategorien:

### 1. SOLID: Single Responsibility Principle (3 Issues)
- **SRP-1**: JpegParser God Class (2,651 LOC)
- **SRP-2**: TiffExifParser Mega Class (9,847 LOC)
- **SRP-3**: ParsedExif God Class (5,066 LOC, 275 methods)

### 2. SOLID: Open/Closed Principle (3 Issues)
- **OCP-1**: JpegParser if-elseif chain
- **OCP-2**: MetadataReader container switch
- **OCP-3**: ValueFactory hard-wired dependencies

### 3. SOLID: Liskov Substitution Principle (4 Issues)
- **LSP-1**: ImageFactory type guard
- **LSP-2**: RegionsFactory type check
- **LSP-3**: LensFactory instanceof
- **LSP-4**: GpsFactory inconsistent returns

### 4. SOLID: Interface Segregation Principle (1 Issue)
- **ISP-1**: BinaryReadAccessInterface fat interface

### 5. SOLID: Dependency Inversion Principle (6 Issues)
- **DIP-1**: ValueFactory IccParser instantiation
- **DIP-2**: StructuredMetadataBuilder dependency
- **DIP-3**: MetadataReader parser instantiations
- **DIP-4**: ConverterFactory bootstrapping
- **DIP-5**: Create parser interfaces
- **DIP-6**: Add factory classes

### 6. DRY Violations (4 Issues)
- **DRY-1**: IsoBmffParser parameter repetition ⚡ Quick Win
- **DRY-2**: GpsConverter encoding decoders ⚡ Quick Win
- **DRY-3**: TiffExifParser tag definitions
- **DRY-4**: RationalConverter type checks

### 7. KISS Violations (4 Issues)
- **KISS-1**: XmpParser nesting (6 levels)
- **KISS-2**: IsoBmffParser conditionals
- **KISS-3**: TiffExifParser loops
- **KISS-4**: AppleDecoder large file

### 8. YAGNI Violations (3 Issues)
- **YAGNI-1**: Evaluate 12 factories
- **YAGNI-2**: Review trivial wrappers
- **YAGNI-3**: Singleton cache

### 9. Law of Demeter (3 Issues)
- **LOD-1**: ValueFactory property chains
- **LOD-2**: RegionsFactory nested access
- **LOD-3**: ExifReader facade

### 10. Separation of Concerns (3 Issues)
- **SOC-1**: ParsedExif separation
- **SOC-2**: JpegParser I/O mixing
- **SOC-3**: MetadataReader coupling

### 11. Convention over Configuration (6 Issues)
- **COC-1**: JpegParser hardcoded limits
- **COC-2**: Namespace URIs
- **COC-3**: Sensor constants
- **COC-4**: Magic numbers
- **COC-5**: Signature registry
- **COC-6**: Factory instantiation

### 12. GRASP Violations (4 Issues)
- **GRASP-1**: KeyedArchiveUnarchiver instanceof
- **GRASP-2**: AppleDecoder type checks
- **GRASP-3**: TiffExifParser instanceof
- **GRASP-4**: GpsConverter nested instanceof

### 13. Documentation (1 Issue)
- **DOC-1**: Migration guide

---

## Prioritäten

### Kritisch (🔴 3 Issues)
1. SRP-1: JpegParser
2. SRP-2: TiffExifParser
3. SRP-3: ParsedExif

### Hoch (🟠 8 Issues)
- OCP-1, DIP-1, DIP-2, DIP-3
- DRY-1, DRY-2 (Quick Wins!)
- DOC-1

### Mittel (🟡 15 Issues)
- LSP-1 bis LSP-4
- ISP-1
- OCP-2, OCP-3
- KISS-1, KISS-2
- LOD-1, LOD-2
- COC-1
- GRASP-1 bis GRASP-3

### Niedrig (⚪ 12 Issues)
- Restliche Issues

---

## Quick Wins ⚡

Beginnen Sie mit diesen einfachen Fixes:

1. **DRY-1**: IsoBmffParser Context-Objekt (2-3 Tage)
2. **DRY-2**: GpsConverter Encoding-Decoder (1 Tag)
3. **LSP-1 bis LSP-4**: Type Guard Fixes (je 4 Stunden)

---

## Nach der Erstellung

### 1. Issues überprüfen
```bash
gh issue list --repo magicsunday/imagemeta
```

### 2. Milestones setzen
```bash
# Beispiel: Quick Wins Milestone
gh issue edit 5 --milestone "Quick Wins" --repo magicsunday/imagemeta
```

### 3. Zuweisen
```bash
# Beispiel: Issue an Developer zuweisen
gh issue edit 1 --assignee username --repo magicsunday/imagemeta
```

### 4. Labels ergänzen (falls nötig)
```bash
# Falls ein Label fehlt
gh issue edit 1 --add-label "needs-review" --repo magicsunday/imagemeta
```

---

## Fehlerbehebung

### Fehler: "Label not found"
```bash
# Labels wurden noch nicht erstellt
# Lösung: Führen Sie zuerst aus:
./create_issues.sh  # Erstellt Labels im Schritt 1
```

### Fehler: "Not authenticated"
```bash
gh auth login --web
```

### Fehler: "Permission denied"
```bash
# Sie brauchen Schreibrechte auf das Repository
# Bitten Sie den Repository-Owner um Zugriff
```

### Python-Fehler: "ModuleNotFoundError: No module named 'yaml'"
```bash
pip3 install pyyaml
```

---

## Tracking-Fortschritt

Nach dem Erstellen der Issues:

1. **Projekt-Board erstellen** (GitHub Projects)
2. **Milestones definieren**:
   - Quick Wins (DRY-1, DRY-2, LSP-*)
   - Phase 1: God Classes (SRP-1, SRP-2, SRP-3)
   - Phase 2: Architecture (OCP, DIP)
   - Phase 3: Code Quality (KISS, GRASP)

3. **Sprint-Planung**:
   - Sprint 1: 2-3 Quick Wins
   - Sprint 2-3: 1 God Class
   - Sprint 4-6: Architecture Improvements

---

## Referenzen

- **RE_AUDIT_REPORT.md**: Detaillierte Violation-Analyse
- **FORENSIC_AUDIT.md**: Original-Audit (2026-02-14)
- **ALL_ISSUES.yaml**: Strukturierte Issue-Definitionen
- **AGENTS.md**: Coding-Standards und Guidelines

---

## Zusammenfassung

**Gesamt-Issues**: 38 ungelöste Violations  
**Bereits gelöst**: 1 (MakerNotes decoders)  
**Teilweise verbessert**: 1 (XmpParser)  

**Empfohlene Vorgehensweise**:
1. ✅ Alle 38 Issues mit Python-Skript erstellen
2. ✅ Quick Wins zuerst angehen (DRY-1, DRY-2)
3. ✅ Dann God Classes (SRP-1, SRP-2, SRP-3)
4. ✅ Fortschritt in RE_AUDIT_REPORT.md tracken
5. ✅ Regelmäßige Re-Audits durchführen

**Viel Erfolg!** 🚀
