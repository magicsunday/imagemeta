<!--
PR-Titel-Empfehlung:
M#: <kurze Beschreibung> — <Bereich>
Beispiel: M1: Unify binary readers — Core/Stream
-->

## Summary
<!-- Kurzbeschreibung der Änderung und des Ziels. -->

**Issue/Milestone:** Closes #<ID> • Milestone M#  
**Scope (allowed files only):** <!-- Liste der Dateien/Verzeichnisse, die in diesem PR geändert werden dürfen. -->  
**Non-Goals:** <!-- Was explizit NICHT Bestandteil ist. -->

---

## Implementation Notes
- Runtime: PHP 8.4, `declare(strict_types=1);`, PSR-12.
- **Streaming only** (Core\Stream/StreamWindow) — keine Ganzdatei-Reads.
- Keine externen Binaries/Extensions; **kein** `exif_read_data()`.
- Value Objects (chainable) nur für EXIF-Ausgabe; kein Rendering.
- **Enums** für gängige Kodierungen genutzt/ergänzt (Encoding/Endianness/IFD/XMP/...).
- **Spec-References** im Code ergänzt/aktualisiert (siehe „References“).

---

## Always Checklist (must be green)
**Composer-Tasks (deine CI-Skripte):**
- [ ] Lint: `composer ci:test:php:lint`
- [ ] PHPStan: `composer ci:test:php:phpstan`
- [ ] Rector (dry-run): `composer ci:test:php:rector`
- [ ] Coding-Style: `composer ci::cgl`
- [ ] PHPUnit: `composer ci:test:php:unit`
- [ ] Coverage ≥ **90 %**: `composer ci:test:php:unit:coverage`
- [ ] JSCPD (Duplikate = 0): `composer ci:test:php:cpd`
- [ ] Sammellauf: `composer ci:test` (sollte grün sein; enthält Lint, PHPStan, Rector-Dry-Run, CS-Dry-Run, Unit)

**Inhaltliche/Code-Guidelines:**
- [ ] **Keine** `mixed`-Signaturen • **kein** `empty()` • **keine** verschachtelten Ternaries
- [ ] Fully-qualified native functions (`\strlen`, `\count`, …) und sinnvolle `use`-Imports
- [ ] **Keine** dynamischen Aufrufe statischer Methoden
- [ ] Sinnvolle **Interfaces** verwendet (wo Verträge sinnvoll sind)
- [ ] Typisierte Klassenkonstanten; redundante Casts/Default-Argumente entfernt
- [ ] Klassen als `readonly`, wo passend; keine redundanten `readonly`-Mods
- [ ] **Eine Klasse je Datei** • Test-Namespaces spiegeln `src/`-Struktur
- [ ] Englische PHPDocs & englische Inline-Kommentare an komplexen Stellen
- [ ] Sinnvolle Namen für Variablen/Konstanten; Magic Numbers/Strings vermieden
- [ ] `array_find` / `array_any` / `array_all` gezielt statt trivialer `foreach` verwendet (wo es die Lesbarkeit verbessert)

---

## EXIF / TIFF / ISOBMFF / XMP Guardrails
- **EXIF-Versionen:** 1.x, 2.x (2.1/2.2/2.21/2.3/2.31/2.32), 3.0 — kompatible Änderungen; falls Tags betroffen: Coverage-Test grün.
- **TIFF/BigTIFF:** Endianness korrekt (`0x2A` / `0x2B`), Inline-Werte-Grenzen, `RATIONAL/SRATIONAL` korrekt.
- **GPS:** Vorzeichen über Ref-Tags (S/W negativ); `0/0`-Kantenfälle robust.
- **Preview/IFD1:** Nur beschreiben (Offsets/Längen/Compression), kein Rendering.
- **ISOBMFF/QuickTime:** Box-Size ≥ Header, 32/64-bit-Größen korrekt; `iloc`-Extents summiert; `data_reference_index ≠ 0` → skip; keine Remote-Refs.
- **XMP:** JPEG APP1-Präfix / ISOBMFF `uuid`; Parser mit `LIBXML_NONET`, keine DTD/Entities; `Alt/Bag/Seq` → Arrays.
- **Fehlerbehandlung:** ausschließlich `ParseError` oder `BoundsError`; keine Warnings/Notices als Kontrollfluss.

---

## Tests
- **Neue/angepasste Tests:** <!-- Auflisten: Klassen/Methoden/Edge-Cases -->
- **Negative Cases:** <!-- Welche Fehlerbilder werden abgedeckt? -->
- **Fixtures/Streams:** <!-- Synthetische Blobs bevorzugt; große Binärdateien vermeiden. -->

---

## M# Sweep — Verify compliance for this milestone
Bitte **alles** verifizieren und kurz die Ergebnisse notieren:
- [ ] `composer ci:test` **grün** (Lint, PHPStan, Rector-Dry-Run, CS-Dry-Run, Unit)
- [ ] `composer ci:test:php:cpd` **0 Duplikate** (JSCPD, Konfig: `.build/.jscpd.json`)
- [ ] `composer ci:test:php:unit:coverage` **≥ 90 %**
- [ ] **ExifCoverageTest** (falls vorhanden): 0 fehlende Tags/Getters (gegen `resources/exif-map.yaml`)
- [ ] Für **jede** Klasse existiert ein Test (Namespace-Spiegelung)

---

## Breaking Changes / Risk / Rollback
- **Breaking changes:** <!-- falls ja: welche und warum notwendig -->
- **Risiken:** <!-- Parser-Sensitivität, Performance, Abhängigkeiten -->
- **Rollback-Plan:** <!-- einfache Revert-Strategie / Feature-Flag falls nötig -->

---

## Changelog
<!-- Kurz und im Stil der Conventional Commits zusammenfassen. -->

---

## References (Specs & Docs)
<!-- Liste der relevanten Kapitel/Abschnitte, jeweils aktuellste Fassung + abweichende ältere, falls relevant -->
- EXIF 3.0 §… ; ggf. EXIF 2.32 §… ; EXIF 2.31 §…
- TIFF 6.0 §…
- ISOBMFF/QuickTime §…
- XMP (Adobe XMP Packet) §…

Docs im Repo:
- `docs/EXIF-210.pdf`, `docs/EXIF-220.pdf`, `docs/EXIF-230.pdf`, `docs/EXIF-231.pdf`, `docs/EXIF-232.pdf`, `docs/EXIF-300.pdf`
- `docs/TIFF6.pdf`

---

## Review Roles (tick what you covered)
- [ ] Planner (Scope/Non-Goals/Guardrails definiert)
- [ ] Spec Writer (AC/Tests präzisiert)
- [ ] Test Agent (RED zuerst)
- [ ] Implementer (GREEN minimal & im Datei-Scope)
- [ ] Static/QA (Stan/CS/Rector/JSCPD clean)
- [ ] Security (Bounds, Offsets, XML-Flags)
- [ ] Reviewer (Minimalität, DX, Spec-Refs, Enums)
- [ ] Release (PR-Text, Labels, Milestone, „Closes #…“)

---

## Senior Reviewer Output Protocol
- Confirm plan alignment with issue scope, acceptance criteria, and allowed file scope.
- Check architecture and clean-code constraints (SOLID, DRY, KISS, YAGNI, GRASP, LoD, SoC) with concrete evidence.
- Prioritize high-impact findings only:
  - 🔴 Critical — must fix before merge (correctness, security, CI/spec violations).
  - 🟡 Important — should fix before merge (maintainability, robustness, test gaps tied to scope).
  - 🟢 Suggestion — optional improvement (only if clearly low-risk and in-scope).
- For each finding include: confidence (0-100), severity, exact file+line, why it matters, rule/spec reference, and a concrete fix snippet.
- Do not report low-confidence or stylistic noise; reward correct defensive patterns and minimal, well-bounded changes.
