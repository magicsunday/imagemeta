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
- [ ] PHPUnit 12 **green** (inkl. negativer Pfade) — `composer ci:test:php:unit`
- [ ] Coverage **≥ 90 %** — `composer ci:test:php:unit:coverage`
- [ ] PHPStan (max) **green** — `composer ci:test:php:phpstan`
- [ ] PHPCS / PHP-CS-Fixer **green** — `composer ci:cgl`
- [ ] Rector **dry-run clean**
- [ ] **Keine** `mixed`-Signaturen • **keine** `empty()` • **keine** verschachtelten Ternaries
- [ ] Fully-qualified native functions verwendet (`\strlen`, `\count`, …)
- [ ] Keine dynamischen Aufrufe statischer Methoden
- [ ] Sinnvolle **Interfaces** eingesetzt (wo Verträge sinnvoll sind)
- [ ] Typisierte Klassenkonstanten; redundante Casts/Default-Args entfernt
- [ ] Klassen als `readonly`, wo passend; keine redundanten `readonly`-Mods
- [ ] **Eine Klasse je Datei** • Test-Namespaces spiegeln `src/`-Struktur
- [ ] Englische PHPDocs & englische Inline-Kommentare an komplexen Stellen
- [ ] Sinnvolle Namen für Variablen/Konstanten; Magic Numbers/Strings vermieden
- [ ] `array_find` / `array_any` gezielt statt trivialer `foreach` verwendet (wo es die Lesbarkeit verbessert)
- [ ] **phpcpd**: 0 Duplikate (Copy/Paste)

---

## EXIF / TIFF / ISOBMFF / XMP Guardrails
- **EXIF-Versionen:** 1.x, 2.x (2.1/2.2/2.21/2.3/2.31/2.32), 3.0 — Änderungen kompatibel; Falls Tags betroffen: Coverage-Test grün.
- **TIFF/BigTIFF:** Endianness korrekt (`0x2A` / `0x2B`), Inline-Werte-Grenzen eingehalten, `RATIONAL/SRATIONAL` korrekt.
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
Führe die Standardprüfung aus und kopiere die Kurzresultate:
- [ ] `composer qa` (Sammellauf) **green**
- [ ] `phpcpd` **0 Duplikate**
- [ ] **ExifCoverageTest**: 0 fehlende Tags/Getters (gegen `resources/exif-map.yaml`)
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
- [ ] Static/QA (Stan/CS/Rector clean)
- [ ] Security (Bounds, Offsets, XML-Flags)
- [ ] Reviewer (Minimalität, DX, Spec-Refs, Enums)
- [ ] Release (PR-Text, Labels, Milestone, „Closes #…“)
