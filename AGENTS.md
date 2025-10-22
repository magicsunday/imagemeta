# Agents.md — MagicSunday/ImageMeta

> Leitfaden für den Einsatz von LLM-Agents (Codex/Copilot/ChatGPT o. ä.) in diesem Repo.
> Ziel: reproduzierbare, sichere und schlanke Patches als **Unified Diffs** – mit Tests, Static Analysis und klaren Guardrails.

## 1) Scope & Prinzipien

* **Ziel des Projekts:** Streaming-Parser für JPEG/HEIC/MOV/MP4; EXIF (inkl. BigTIFF), XMP, QuickTime/ISOBMFF-Keys – **rein PHP 8.4**, ohne `exif_read_data()` oder CLI-Tools wie `exiftool`.
* **Kernprinzipien:**

    * **Streaming only**: `Core\Stream`/`StreamWindow` statt Ganzdatei-Reads.
    * **Sicherheit**: harte Bounds-Checks, Längen/Offset-Guards, `LIBXML_NONET`.
    * **Stil/Qualität**: `strict_types=1`, PSR-12, PHPUnit 12, PHPStan 6.
    * **Konsistenz**: Patches als **Unified Diff**, minimalinvasiv, keine API-Breaks ohne Changelog.
    * **kein** mixed, empty
    * **PSR-12**: `strict_types=1`, `declare(strict_types=1);`
    * folge und halte dich an KISS, SOLID, DRY, YAGNI, GRASP, LoD, SoC, CoC, ...
    * Klassen/Methoden immer mit PHPdoc-Block versehen (in englisch) der beschreibt, was die Klasse/Methode macht, Parameter eine Methode beschreiben
    * erklärende Inline-Kommentare im Code in Englisch an komplexen Code-Stellen
    * PHPUnit mit Attributen verwenden

## 2) Agenten-Rollen

| Agent           | Verantwortung                                                                               | Ein-/Ausgabe                                               |
| --------------- | ------------------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| **Planner**     | Issue/Milestone lesen, Arbeitsumfang präzisieren, Dateiliste & Guardrails definieren.       | In: Issue-Text; Out: feingranulare Sub-Tasks & Datei-Scope |
| **Spec Writer** | Akzeptanzkriterien (AC), Testfälle, Fehlerbilder („rot“) präzisieren.                       | In: Planner-Ergebnis; Out: Test-Spezifikation              |
| **Test Agent**  | **Tests zuerst** (PHPUnit): synthetische Fixtures, negative Pfade (ParseError/BoundsError). | In: Spec; Out: Unified Diff mit Tests                      |
| **Implementer** | Feature/Fix implementieren (nur freigegebene Dateien), guards & streaming beachten.         | In: Tests (rot), Repo-Kontext; Out: Unified Diff           |
| **Static/QA**   | PHPStan/PHPCS Fixes, kleine Refactors ohne Semantikänderung.                                | In: Fehlerlogs; Out: Unified Diff                          |
| **Security**    | Längen-Limits, Offset-Checks, XML-Sicherheitsflags, DoS-Vermeidung.                         | In: Patch; Out: Review-Anmerkungen/Patch                   |
| **Reviewer**    | Minimalität, Lesbarkeit, DX, Abgleich mit AC & Tests.                                       | In: PR + Diffs; Out: Review-Kommentare / Mini-Patches      |
| **Release**     | Commit/PR-Text, Changelog, Labels/Milestone, „Closes #…“.                                   | In: finaler Patch; Out: PR-Beschreibung/Tagging            |

> **Hinweis:** Eine Person kann mehrere Rollen übernehmen – die Rollen dienen als Checklisten.

## 3) Standard-Werkzeuge

* **Runtime**: PHP 8.4
* **Tests**: PHPUnit 12 (`composer ci:test:php:unit`)
* **Static**: PHPStan max (`composer ci:test:php:phpstan`), PHPCS (`composer ci:test:php:cgl`)
* **Git**: Unified Diffs anwenden per `git apply -p0 patch.diff`

## 4) Guardrails (für alle Patches)

* **Nur erlaubte Dateien ändern** (pro Issue explizit benennen).
* **Kein** Einbinden externer Binaries/Extensions, kein `exif_read_data()`.
* **Bounds-Checks** bei allen Längen/Offsets, **Max-Limits** für Segment/Box/Packet.
* **TIFF/EXIF**: Endianness beachten; Classic TIFF (0x2A) & BigTIFF (0x2B, 64-bit).
* **ISOBMFF**: `iloc` multi-extent korrekt konkat., `data_reference_index != 0` skippen.
* **XMP**: `XMLReader` mit `LIBXML_NONET`, keine DTD/Entities; Container (Alt/Bag/Seq) als Arrays.
* **QuickTime**: `keys/ilst` und `mdta` Varianten; `content.identifier` extrahieren.
* **Fehlerklassen**: ausschließlich `ParseError` / `BoundsError` für Parserfehler.
* **Output-Format**: immer **Unified Diff**; neue Dateien vollständig im Patch.

## 5) Prozess-Pipeline (Agent Playbook)

1. **Planner**

    * Lies Issue/Milestone. Definiere: *Dateiliste*, *Nicht-Ziele*, *Guards*.
    * Verweise auf vorhandene Prompts (im Repo unter `prompts/`).

2. **Spec Writer**

    * Ergänze **AC** & **Testfälle** (positive/negative).
    * Formuliere „rot“-Erwartungen (welche Fehler auftreten sollen).

3. **Test Agent (RED)**

    * Schreibe Tests **zuerst**.
    * Keine großen Binär-Fixtures → **synthetische** Streams/Blobs.
    * Output: Unified Diff (nur `tests/**`).

4. **Implementer (GREEN)**

    * Liefere minimalen Patch, der Tests grün macht.
    * Halte dich an Datei-Scope & Guards.

5. **Static/QA**

    * PHPStan/PHPCS, kleine Aufräum-Patches.

6. **Reviewer**

    * Check: Streaming, Guards, Minimalität, Lesbarkeit, AC erfüllt?

7. **Release**

    * Commit mit Conventional Commit, PR-Text, verlinke Issue/Milestone, Changelog.

## 6) Prompts (Kurzschablonen)

**Implementierung (pro Issue)**

```
Rolle: Implementer. Erfülle Issue „<TITEL>“.
Kontext: PHP 8.4, strict_types=1, PSR-12, Streaming-Parser.
Datei-Scope: <Liste>
Guards: Bounds-Checks, Max-Größen, LIBXML_NONET, keine externen Tools.
Output: Unified Diff + Conventional Commit-Message.
```

**Tests zuerst**

```
Rolle: Test Agent. Erstelle ausschließlich PHPUnit-Tests für „<TITEL>“.
Synthetische Fixtures, negative Pfade, keine großen Dateien.
Output: Unified Diff (nur tests/**) + kurze Erklärung.
```

**Fix-Loop**

```
Rolle: Implementer. Hier ist der PHPUnit-Output (rot):
<OUTPUT>
Liefere einen minimalen Unified Diff, der nur die Fehler adressiert.
```

**PR-Text**

```
Rolle: Release. Schreibe PR-Beschreibung (Übersicht, Details, Tests, Risiken, Changelog).
```

> Fertige, ausführliche Prompts liegen in `prompts/` (M2–M6).

## 7) Domain-Checks (Spickzettel)

* **EXIF/BigTIFF**: Inline-Werte ≤4 (Classic) bzw. ≤8 Bytes (BigTIFF); RATIONAL/SRATIONAL korrekt lesen; GPS-Vorzeichen aus Ref (S/W negativ).
* **ISOBMFF**: Boxgröße ≥ Header; 32/64-bit Größen beachten; `iloc` Extents addieren; absolute Offsets nur bei `constructionMethod=0`, `dataRefIdx=0`.
* **XMP**: Signaturen

    * JPEG: APP1 mit Präfix `http://ns.adobe.com/xap/1.0/\0`
    * ISOBMFF: `uuid` mit GUID `BE7ACFCB-97A9-42E8-9C71-999491E3AFAC` oder Item `application/rdf+xml`
* **Security**: Kein Netz/DTD, harte Limits, Exceptions statt Warnings.

## 8) Definition of Done (DoD)

* Tests: **grün** (inkl. Negativ-Fälle), PHPStan: **grün**, PHPCS: **grün**.
* Patches minimal & im vereinbarten Datei-Scope.
* AC erfüllt; README/Changelog/PR-Text bei API-Sichtbarkeit aktualisiert.
* **Milestone/Issue verknüpft**, Commit/PR mit „Closes #…“.

## 9) Beispiel: Agent-Karte „M3 – ISOBMFF abrunden“

* **Input**: Issue-Text (Beschreibung, Aufgaben, AC, Testfälle).
* **Datei-Scope**: `src/Parse/IsoBmff/IsoBmffExtractor.php`, ggf. `src/Parse/IsoBmff/BoxGuards.php`, `tests/IsoBmff/**`.
* **Guards**: Box-Size ≥ Header, U64 für Offsets/Längen, Extent-Summe ≤ Datei-größe, `data_reference_index≠0` skip.
* **Erwartung**: EXIF via Exif-Box **oder** iloc-Item; XMP via uuid/Item; `content.identifier` gefunden; korrupt → `ParseError`.

## 10) Häufige Fehler & Gegenmittel

* **Zu breite Änderungen** → Datei-Scope im Prompt hart vorgeben.
* **Ganzdatei-Reads** → im Review ablehnen, Streaming fordern.
* **Fehlende Guards** → Tests mit korrupten Längen/Offsets hinzufügen.
* **Fragile XMP-Parser** → `LIBXML_NONET`, defekte XMLs als Teilresultat behandeln, keine Fatals.

---

**Kontakt/Owner:** *MagicSunday* (Europe/Berlin)
**Ordnerstruktur (relevant):** `src/Core`, `src/Detect`, `src/Parse/{Jpeg,IsoBmff,Tiff,Xmp}`, `src/Model`, `src/Convenience`, `src/MakerNotes`, `tests/**`, `prompts/**`, `docs/**`.

---
