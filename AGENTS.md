# AGENTS.md — MagicSunday/ImageMeta

> Leitfaden für LLM-Agents (Codex/Copilot/ChatGPT o. ä.) in diesem Repo.
> **Ziel:** reproduzierbare, sichere und schlanke **PRs** mit Tests, Static Analysis und klaren Guardrails.
> **Hinweis:** Es werden **keine Unified-Diff-Patches** erwartet.

---

## 1) Scope & Prinzipien

**Projektziel:** Streaming-Parser für JPEG/HEIC/MOV/MP4; EXIF (Classic-TIFF & BigTIFF), XMP, QuickTime/ISOBMFF – **reines PHP 8.4**, ohne `exif_read_data()` oder externe CLI-Tools.

**EXIF-Support:** EXIF **1.x**, **2.x** (2.1/2.2/2.21/2.3/2.31/2.32) und **3.0** (Endianness, Classic-TIFF `0x2A`, BigTIFF `0x2B`/64-bit) vollständig berücksichtigen.
* siehe EXIF-Spezifikationen unter 
  - `docs/EXIF-210.pdf`
  - `docs/EXIF-220.pdf`
  - `docs/EXIF-230.pdf`
  - `docs/EXIF-231.pdf`
  - `docs/EXIF-232.pdf`
  - `docs/EXIF-300.pdf`
* siehe TIFF 6.0-Spezifikation unter 
  - `docs/TIFF6.pdf`

**Grundsätze (MUSS):**

* **Streaming only:** `Core\Stream`/`StreamWindow`; keine Ganzdatei-Reads.
* **Sicherheit:** harte Bounds-Checks; Max-Limits für Längen/Offsets; XMP strikt mit `LIBXML_NONET` (keine DTD/Entities).
* **Code-Qualität:** `declare(strict_types=1);`, PSR-12, **kein** `mixed`, **kein** `empty()`.
* **Kompatibilität:** minimalinvasive Änderungen; keine API-Breaks ohne Changelog.
* **Struktur & Docs:** genau **eine Klasse je Datei**; sinnvolle Bezeichner; **englische** PHPDoc-Blöcke (Zweck/Params/Return); **englische** Inline-Kommentare an komplexen Stellen.
* **Tests & Static:** PHPUnit 12 (Attribute), PHPStan (max Level), PHPCS.
* **Namensräume spiegeln:** Tests folgen `src/`-Namespaces (z. B. `…\Parse\IsoBmff` → `…\Tests\Parse\IsoBmff`).

**Coding-Leitplanken (MUSS):**

* Tests **für jede Klasse** (positiv/negativ).
* **Typisierte Klassenkonstanten**; sprechende Konstanten statt Magic Numbers (z. B. `IFD0_MAKE` statt `0x010F`).
* Kein verschachtelter ternärer Operator.
* Null-Referenzen erkennen/absichern.
* Vollqualifizierte Funktionsaufrufe wo sinnvoll; Importe statt wiederholter Qualifier.
* Klassen als `readonly`, wenn ausschließlich `readonly`-Properties; redundante Modifizierer entfernen.
* Redundante Casts/Argumente vermeiden (Defaults nutzen).
* Statische Methoden **nicht** dynamisch (`->`) aufrufen.
* Unbenutzte Methoden/Klassen entfernen.
* **`array_find()`** statt manueller `foreach`, wo es Lesbarkeit/Performance verbessert.

---

## 2) Agenten-Rollen

| Agent           | Verantwortung                                                                                | Ein-/Ausgabe                                      |
| --------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| **Planner**     | Issue/Milestone lesen; Datei-Scope, Nicht-Ziele, Guardrails definieren.                      | In: Issue; Out: Sub-Tasks + Datei-Scope           |
| **Spec Writer** | Akzeptanzkriterien (AC) & Testfälle (inkl. „rot“/Fehlerbilder) präzisieren.                  | In: Planner; Out: Test-Spezifikation              |
| **Test Agent**  | **RED**: PHPUnit-Tests zuerst; synthetische Streams/Blobs; Negativpfade (Parse/BoundsError). | In: Spec; Out: Commits unter `tests/**`           |
| **Implementer** | **GREEN**: Implementiert nur im freigegebenen Datei-Scope; Streaming & Guards beachten.      | In: rote Tests; Out: Commits, die grün machen     |
| **Static/QA**   | PHPStan/PHPCS grün; kleine Refactors ohne Semantikwechsel.                                   | In: Linter/Stan-Output; Out: Commits              |
| **Security**    | Sicherheitsreview: Längen/Offsets, XML-Flags, DoS-Vermeidung.                                | In: PR-Diff; Out: Review-Anmerkungen/Mini-Commits |
| **Reviewer**    | Minimalität, Lesbarkeit, AC-Erfüllung, DX.                                                   | In: PR; Out: Review-Kommentare/Mini-Commits       |
| **Release**     | PR-Text, Changelog, Labels/Milestone, „Closes #…“, Tagging.                                  | In: finaler PR; Out: Release/Tag                  |

> Rollen sind **Checklisten**; eine Person darf mehrere Rollen übernehmen.

---

## 3) Standard-Werkzeuge & Commands

* **Runtime:** PHP 8.4
* **Tests:** `composer ci:test:php:unit`
* **Coverage:** `composer ci:test:php:unit:coverage` (prüfen/steigern)
* **Static:** `composer ci:test:php:phpstan`
* **CGL/Style:** `composer ci:cgl` (Änderungen vollständig übernehmen)

**Git-Flow (ohne Diffs):**

* Branch-Schema: `feat/<area>-<slug>`, `fix/<area>-<slug>`, `chore/<area>-<slug>`
* Commits: **Conventional Commits** (z. B. `feat(tiff): add BigTIFF SRATIONAL reader`)
* PR öffnen, CI grün halten, Reviewer zuweisen.

---

## 4) Guardrails (für **alle** Änderungen)

**Allgemein**

* **Nur** die im Issue freigegebenen Dateien ändern.
* **Keine** externen Binaries/Extensions; **kein** `exif_read_data()`.
* Harte **Max-Limits** für Segment/Box/Packet-Längen; ausnahmslos Bounds-Checks.

**TIFF/EXIF**

* Endianness strikt respektieren (Classic `0x2A`, BigTIFF `0x2B`/64-bit).
* Inline-Werte: ≤ 4 Bytes (Classic) / ≤ 8 Bytes (BigTIFF).
* `RATIONAL/SRATIONAL` korrekt lesen; GPS-Vorzeichen über Ref-Tags (S/W negativ).

**ISOBMFF/QuickTime**

* Box-Size ≥ Header; 32/64-bit Größen korrekt validieren.
* `iloc`-Extents **summieren**; absolute Offsets nur bei `constructionMethod = 0` **und** `data_reference_index = 0`.
* `data_reference_index ≠ 0` **skippen** (keine Remote-Refs/Netz).

**XMP**

* JPEG: APP1-Präfix `http://ns.adobe.com/xap/1.0/\0`.
* ISOBMFF: `uuid` **BE7ACFCB-97A9-42E8-9C71-999491E3AFAC** oder Item-`application/rdf+xml`.
* `XMLReader` + `LIBXML_NONET`; **keine** DTD/Entities; Alt/Bag/Seq → Arrays.

**Fehlerbehandlung**

* Parserfehler ausschließlich als **`ParseError`** oder **`BoundsError`** werfen.
* Keine Warnings/Notices als Kontrollfluss.

---

## 5) Pipeline (Agent-Playbook)

1. **Planner**
   Datei-Scope, Nicht-Ziele, Guardrails festlegen; auf `prompts/` verweisen.

2. **Spec Writer**
   **AC** & **Testfälle** (positiv/negativ) definieren; „rot“ klar benennen.

3. **Test Agent — RED**
   Nur `tests/**` committen; synthetische Streams/Blobs statt großer Binär-Fixtures; Fehlerpfade abdecken.

4. **Implementer — GREEN**
   Minimaler Code-Change innerhalb Datei-Scope, der Tests grün macht; Streaming & Guardrails einhalten.

5. **Static/QA**
   PHPStan/PHPCS grün; kleine Cleanups ohne Semantikwechsel.

6. **Security**
   Längen/Offsets/XMP-Flags prüfen; potentielle DoS-Flächen minimieren.

7. **Reviewer & Release**
   Review auf Minimalität/DX/AC; PR-Text, Changelog, Labels, „Closes #…“, Tag.

---

## 6) Prompt-Schablonen

**Implementierung (pro Issue)**

```
Rolle: Implementer. Erfülle Issue „<TITEL>“.
Kontext: PHP 8.4, strict_types=1, PSR-12, Streaming-Parser.
Datei-Scope: <Liste der erlaubten Dateien>
Guards: Bounds-Checks, Max-Größen, LIBXML_NONET, keine externen Tools.
Output: Commits (Conventional Commits) auf Branch + Pull Request.
```

**Tests zuerst**

```
Rolle: Test Agent. Schreibe ausschließlich PHPUnit-Tests für „<TITEL>“.
Nutze synthetische Streams/Blobs; decke Negative Pfade (ParseError/BoundsError) ab.
Output: Commits unter tests/**; CI-Ausgabe kurz zusammenfassen.
```

**Fix-Loop**

```
Rolle: Implementer. PHPUnit-Ausgabe (rot):
<OUTPUT>
Liefere minimalen Code-Change im freigegebenen Datei-Scope, der ausschließlich die gezeigten Fehler behebt; committe mit Conventional Commit.
```

**PR-Text**

```
Rolle: Release. Schreibe PR-Beschreibung (Übersicht, Details, Tests, Risiken, Changelog, „Closes #…“).
```

> Ausführliche Prompts liegen in `prompts/` (M2–M6).

---

## 7) Domain-Spickzettel

* **EXIF/BigTIFF:** Inline-Grenzen; `RATIONAL/SRATIONAL` korrekt; GPS-Ref → Vorzeichen; Offset-Arithmetik in `uint64`.
* **ISOBMFF:** Header/Größen valid; 32/64-bit Längen; `iloc`-Extents summieren; keine Remote-Refs.
* **XMP:** Signaturen prüfen; Netz/DTD verbieten; defekte XML robust handhaben (Teilresultate, keine Fatals).
* **Security:** harte Limits, Exceptions statt Warnings; kein Netz-I/O.

---

## 8) Definition of Done (DoD)

* ✅ PHPUnit **grün** (inkl. Negativfälle)
* ✅ **Coverage geprüft** (`ci:test:php:unit:coverage`) – Abdeckung **nicht schlechter**
* ✅ PHPStan **grün** (mind. geänderte Dateien)
* ✅ PHPCS/CGL **grün**; Format-Änderungen übernommen
* ✅ Änderungen **minimal** und im vereinbarten Datei-Scope
* ✅ AC erfüllt; ggf. README/Changelog/PR-Text aktualisiert
* ✅ Issue/Milestone verknüpft; PR mit **Conventional Commits** & **„Closes #…“**

---

## 9) Beispielkarte — „M3: ISOBMFF abrunden“

* **Input:** Issue beschreibt EXIF/XMP-Extraktion aus ISOBMFF (Exif-Box **oder** `iloc`-Item), inkl. Fehlerfälle.
* **Datei-Scope:**

    * `src/Parse/IsoBmff/IsoBmffExtractor.php`
    * `src/Parse/IsoBmff/BoxGuards.php` (falls benötigt)
    * `tests/Parse/IsoBmff/**`
* **Guards:** Box-Size ≥ Header; Offsets/Längen in `uint64`; Extent-Summe ≤ Datei-Größe; `data_reference_index ≠ 0` → skip.
* **Erwartung:** EXIF via Exif-Box **oder** `iloc`; XMP via `uuid`/Item; `content.identifier` extrahiert; korrupt → `ParseError`.
* **Output:** Branch `feat/isobmff-fixes`, Commit-Serie (RED→GREEN→QA), PR mit CI grün.

---

## 10) Häufige Fehler & Gegenmaßnahmen

* **Zu breiter Change-Scope** → Datei-Scope im Prompt **hart** vorgeben.
* **Ganzdatei-Reads** → im Review ablehnen; Streaming fordern.
* **Fehlende Guards** → Negative Tests mit korrupten Längen/Offsets ergänzen.
* **Fragiler XMP-Parser** → `LIBXML_NONET`, keine DTD/Entities, defekte XML tolerant behandeln.
* **Magic Numbers bei Tags** → zentrale Konstanten nutzen/ergänzen.

---

## 11) Vor-Commit-Checkliste

* [ ] Nur freigegebene Dateien geändert
* [ ] Streaming beibehalten; keine ungeplanten Buffer/Ganzdatei-Reads
* [ ] Harte Bounds-Checks & Max-Limits vorhanden
* [ ] Tests (inkl. negativ) geschrieben/aktualisiert; Coverage geprüft
* [ ] PHPStan/PHPCS grün
* [ ] Kein `mixed`, kein `empty()`, keine verschachtelten Ternaries
* [ ] Sinnvolle Konstanten statt Magic Numbers
* [ ] Conventional Commits; PR mit „Closes #…“

---

**Owner/Contact:** *MagicSunday* (Europe/Berlin)
**Struktur:** `src/Core`, `src/Detect`, `src/Parse/{Jpeg,IsoBmff,Tiff,Xmp}`, `src/Model`, `src/Convenience`, `src/MakerNotes`, `tests/**`, `prompts/**`, `docs/**`.
