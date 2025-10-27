# AGENTS.md — MagicSunday/ImageMeta

> Leitfaden für LLM-Agents (Codex/Copilot/ChatGPT o. ä.) in diesem Repo.
> **Ziel:** reproduzierbare, sichere und schlanke **Pull Requests** mit Tests, Static Analysis und klaren Guardrails.
> **Hinweis:** Es werden **keine Unified-Diff-Patches** erwartet (Workflow: Branch → Commits → PR).

---

## 1) Scope & Prinzipien

**Projektziel:** Streaming-Parser für JPEG/HEIC/MOV/MP4; EXIF (Classic-TIFF & BigTIFF), XMP, QuickTime/ISOBMFF — **reines PHP 8.4**, ohne `exif_read_data()` und ohne externe CLI-Tools.

**EXIF-Support (muss vollständig sein):** EXIF **1.x**, **2.x** (2.1/2.2/2.21/2.3/2.31/2.32) und **3.0** (Endianness, Classic-TIFF `0x2A`, BigTIFF `0x2B`/64-bit).
**Referenzen im Repo:**

* `docs/EXIF-210.pdf`, `docs/EXIF-220.pdf`, `docs/EXIF-230.pdf`, `docs/EXIF-231.pdf`, `docs/EXIF-232.pdf`, `docs/EXIF-300.pdf`
* TIFF 6.0: `docs/TIFF6.pdf`

**Grundsätze (MUSS):**

* **Streaming only:** `Core\Stream`/`StreamWindow` — keine Ganzdatei-Reads.
* **Sicherheit:** harte Bounds-Checks; Max-Limits für Längen/Offsets; XMP strikt mit `LIBXML_NONET` (keine DTD/Entities, kein Netz-I/O).
* **Code-Qualität:** `declare(strict_types=1);`, PSR-12, **kein** `mixed`, **kein** `empty()`.
* **Kompatibilität:** minimalinvasive Änderungen; keine API-Breaks ohne Changelog.
* **Struktur & Doku:** genau **eine Klasse je Datei**; sinnvolle Bezeichner; **englische** PHPDoc-Blöcke (Zweck/Params/Return) und **englische** Inline-Kommentare an komplexen Stellen.
* **Tests & Static:** PHPUnit 12 (Attribute), PHPStan (max Level), PHPCS.
* **Namensräume spiegeln:** Tests folgen der `src/`-Struktur (z. B. `…\Parse\IsoBmff` → `…\Tests\Parse\IsoBmff`).
* **Model/VOs:** Auslese liefert **Value-Objects** (sinnvoll gruppiert) mit **fluentem Zugriff**/Chaining.

**Coding-Leitplanken (MUSS):**

* Tests **für jede Klasse** (positiv/negativ, Fehlerpfade).
* **Typisierte Klassenkonstanten**; sprechende Konstanten statt Magic Numbers (z. B. `IFD0_MAKE` statt `0x010F`).
* Kein verschachtelter ternärer Operator.
* Null-Referenzen erkennen/absichern.
* Vollqualifizierte Funktionsaufrufe, wo sinnvoll (Performance-kritisch), sonst per `use` importieren (`use function`/`use const` möglich).
* Klassen als `readonly`, wenn ausschließlich `readonly`-Properties; redundante Modifizierer entfernen.
* Redundante Casts/Argumente vermeiden (Defaults nutzen).
* Statische Methoden **nicht** dynamisch aufrufen (also `Class::method()`, nicht `->`).
* Unbenutzte Methoden/Klassen entfernen.
* **`array_find()`** statt manueller `foreach`, wo es Lesbarkeit/Performance verbessert.
* **Interfaces** dort nutzen, wo klare Austauschbarkeit/Verträge sinnvoll sind.
* Kein `@deprecated`: Entfallene APIs **direkt** entfernen (mit Changelog).

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
* **Coverage:** `composer ci:test:php:unit:coverage` (Ziel ≥ **90 %**)
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
* Harte **Max-Limits** für Segment/Box/Packet-Längen; immer Bounds-Checks; kein Netz-I/O.

**TIFF/EXIF**

* Endianness strikt (Classic `0x2A`, BigTIFF `0x2B`/64-bit).
* Inline-Werte: ≤ 4 Bytes (Classic) / ≤ 8 Bytes (BigTIFF).
* `RATIONAL/SRATIONAL` korrekt; GPS-Vorzeichen über Ref-Tags (S/W negativ).

**ISOBMFF/QuickTime**

* Box-Size ≥ Header; 32/64-bit-Größen korrekt validieren.
* `iloc`-Extents **summieren**; absolute Offsets nur bei `constructionMethod=0` **und** `data_reference_index=0`.
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

1. **Planner** — Datei-Scope, Nicht-Ziele, Guardrails festlegen; auf `prompts/` verweisen.
2. **Spec Writer** — **AC** & **Testfälle** (positiv/negativ) definieren; „rot“ klar benennen.
3. **Test Agent (RED)** — nur `tests/**` committen; synthetische Streams/Blobs statt großer Binär-Fixtures.
4. **Implementer (GREEN)** — minimaler Code-Change im Datei-Scope, der die Tests grün macht; Streaming & Guards einhalten.
5. **Static/QA** — PHPStan/PHPCS grün; kleine Cleanups ohne Semantikwechsel.
6. **Security** — Längen/Offsets/XMP-Flags prüfen; DoS-Flächen minimieren.
7. **Reviewer & Release** — Review auf Minimalität/DX/AC; PR-Text, Changelog, Labels, „Closes #…“, Tag.

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

---

## 7) Domain-Spickzettel

* **EXIF/BigTIFF:** Inline-Grenzen; `RATIONAL/SRATIONAL` korrekt; GPS-Ref → Vorzeichen; Offset-Arithmetik in `uint64`.
* **ISOBMFF:** Header/Größen valid; 32/64-bit-Längen; `iloc`-Extents summieren; keine Remote-Refs.
* **XMP:** Signaturen prüfen; Netz/DTD verbieten; defekte XML robust handhaben (Teilresultate, keine Fatals).
* **Security:** harte Limits, Exceptions statt Warnings; absolut kein Netz-I/O.

---

## 8) Definition of Done (DoD)

* ✅ PHPUnit **grün** (inkl. Negativfälle)
* ✅ **Coverage ≥ 90 %** (via `ci:test:php:unit:coverage`)
* ✅ PHPStan **grün** (mind. alle geänderten Dateien)
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
* [ ] Tests (inkl. negativ) geschrieben/aktualisiert; **Coverage ≥ 90 %**
* [ ] PHPStan/PHPCS grün
* [ ] Kein `mixed`, kein `empty()`, keine verschachtelten Ternaries
* [ ] Sinnvolle Konstanten statt Magic Numbers; sprechende Bezeichner
* [ ] Interfaces dort, wo Verträge sinnvoll sind
* [ ] Conventional Commits; PR mit „Closes #…“

---

## 12) Compliance-Katalog (für bestehende Implementierung prüfen & ggf. anpassen)

**Umsetzungsrahmen**

* [ ] EXIF 1.x/2.x/3.0 konform (siehe PDFs unter `docs/…`)
* [ ] TIFF 6.0 beachtet (`docs/TIFF6.pdf`)
* [ ] PHP 8.4+ Features/Kompatibilität
* [ ] KISS, SOLID, DRY, YAGNI, GRASP, LoD, SoC, CoC eingehalten

**Build-/CI-Pflichten vor jedem Commit**

* [ ] `composer ci:test:php:unit:coverage` **grün** (≥ 90 %)
* [ ] `composer ci:test:php:phpstan` **grün** (mind. geänderte Dateien)
* [ ] `composer ci:cgl` ausgeführt, Format-Änderungen übernommen

**Coding**

* [ ] Sinnvolle **Interfaces** verwendet
* [ ] Kein `@deprecated` – Entfallenes entfernt
* [ ] Tests für **jede** Klasse (inkl. Negativfälle)
* [ ] Keine `mixed`-Typen, keine `empty()`-Aufrufe
* [ ] **`array_find()`** genutzt, wo passend
* [ ] **Typisierte Klassenkonstanten**
* [ ] Redundante Casts/Default-Argumente entfernt
* [ ] Keine `{}` für einfache String-Interpolation
* [ ] Keine verschachtelten Ternaries
* [ ] Null-Pointer-Risiken behandelt
* [ ] FQN/Imports sinnvoll eingesetzt (`use`, `use function`, `use const`)
* [ ] Klassen `readonly` wenn möglich; redundante `readonly` entfernt
* [ ] Statische Methoden **nicht** via `->`
* [ ] Redundante/ungenutzte Methoden/Klassen entfernt
* [ ] **Eine Klasse je Datei**
* [ ] **Englische** PHPDocs & erklärende **englische** Inline-Kommentare
* [ ] Test-Namespaces spiegeln `src/`-Struktur
* [ ] Variablen/Konstanten **aussagekräftig** benannt

---

**Owner/Contact:** *MagicSunday* (Europe/Berlin)
**Struktur:** `src/Core`, `src/Detect`, `src/Parse/{Jpeg,IsoBmff,Tiff,Xmp}`, `src/Model`, `src/Convenience`, `src/MakerNotes`, `tests/**`, `prompts/**`, `docs/**`.
