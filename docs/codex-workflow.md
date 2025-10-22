# Codex-Workflow für MagicSunday/ImageMeta

Dieser Leitfaden zeigt, wie du **Milestones & Issues** in GitHub mit einem Code-LLM (z. B. „Codex“/Copilot/ChatGPT) nutzt, um Patches als **Unified Diffs** zu erzeugen, Tests laufen zu lassen und PRs zu erstellen.

## Voraussetzungen

* PHP **8.4**, Composer
* PHPUnit 12, PHPStan (im Projekt per Composer Scripts)
* GitHub CLI `gh` (optional, aber praktisch)
* Editor mit LLM-Chat (VS Code Copilot Chat / ChatGPT im Browser)

```bash
composer install
composer ci:test:php:unit      # PHPUnit
composer ci:test:php:phpstan   # PHPStan
```

## 1) Milestones & Issues anlegen

> **Wichtig:** Das LLM liest deine GitHub-Issues **nicht automatisch**. Du fütterst die Inhalte als Prompt (siehe Schritt 2).

### Milestones (UI)

* **Issues → Milestones → New milestone**
* Title z. B.: `M2 – JPEG-Pfad finalisieren`
* Description: den vorbereiteten Text einfügen (kurz & prägnant)

### Issues (UI)

* **Issues → New issue → Open a blank issue**
* Title: z. B. `M2 – JPEG-Pfad finalisieren`
* Description: **komplette** Vorlage (Beschreibung, Aufgaben, AC, Testfälle, DoD)
* Milestone: `M2 – …` auswählen, Labels setzen

### Alternativ per CLI

```bash
gh milestone create "M2 – JPEG-Pfad finalisieren" -d "APP1/EXIF+XMP, Robustheit"
gh issue create -t "M2 – JPEG-Pfad finalisieren" -F issue-m2.md -m "M2 – JPEG-Pfad finalisieren" -l "type:feature,area:jpeg"
```

## 2) Prompts an das LLM geben

Lege die gelieferten Prompts im Repo an (empfohlen):

```
prompts/
  m2-impl.txt   m2-tests.txt   m2-pr.txt
  m3-impl.txt   m3-tests.txt   m3-pr.txt
  m4-impl.txt   m4-tests.txt   m4-pr.txt
  m5-impl.txt   m5-tests.txt   m5-pr.txt
  m6-impl.txt   m6-tests.txt   m6-pr.txt
```

**So verwendest du sie:**

1. Öffne deinen Code-Chat.
2. Kopiere den **gesamten Inhalt** von `prompts/<milestone>-impl.txt` in den Chat.

    * Die Prompts enthalten: Kontext, Aufgaben, ACs, Dateiliste, **Ausgabeformat: Unified Diff**.
3. Das Modell gibt dir einen **Patch (git unified diff)** zurück + Commit-Message.

> Tipp: Falls du nur Tests zuerst willst: nimm `*-tests.txt`.
> Fixes nach fehlgeschlagenen Tests: nutze den *Fix-Loop* weiter unten.

## 3) Patch anwenden & prüfen

**Patch speichern & anwenden**

```bash
# Speichere den Modell-Output als patch.diff (ohne Code-Block-Markup)
git apply -p0 patch.diff
```

**Tests & Static Analysis**

```bash
composer ci:test:php:unit
composer ci:test:php:phpstan
```

Falls Tests **fehlschlagen**:

* Kopiere den **PHPUnit-Output** in den Chat und nutze einen kurzen Prompt:

  > *„Hier ist die Testausgabe (rot). Analysiere die Fehlerursachen und liefere einen **Unified Diff**, der nur die notwendigen Stellen ändert.“*
* Patch erneut anwenden → Tests wiederholen, bis **grün**.

## 4) Branch, Commit, PR

```bash
git checkout -b feat/m2-jpeg-finalize
git add -A
git commit -m "feat(jpeg): robust APP1 EXIF+XMP handling, extract ICC/IPTC, add guards

Closes #<ISSUE_NR>"
git push -u origin feat/m2-jpeg-finalize
```

**PR erstellen**

* PR-Beschreibung per `prompts/<milestone>-pr.txt` generieren (LLM fragen oder Vorlage kopieren).
* PR mit **Milestone** & **Issue** verknüpfen (Labels hinzufügen).
* Hinweis wie „Closes #123“ schließt das Issue bei Merge automatisch.

## 5) Fix-Loop bei Reviews

Kommt Feedback im Review:

* Kopiere relevante Reviewer-Kommentare in den Chat mit dem Zusatz:

  > *„Erzeuge einen minimalen **Unified Diff**, der ausschließlich diese Punkte adressiert. Keine API-Breaks, gleiche Stilregeln.“*
* Patch anwenden → Tests/Stan laufen lassen → Pushen.

## 6) Guardrails (wichtig für verlässliche LLM-Patches)

* **Scope eng stecken:** „Nur Dateien X/Y/Z ändern“, „keine externen Abhängigkeiten“.
* **Streaming betonen:** Keine Ganzdatei-Reads (nur `Core\Stream`/`StreamWindow`).
* **Sicherheits-Guards:** Max-Segmentgrößen, Bounds-Checks, `LIBXML_NONET`.
* **Ausgabeformat:** Immer **Unified Diff** anfordern, neue Dateien **vollständig** im Patch.
* **Konventionen:** PHP 8.4, `strict_types=1`, PSR-12.

## 7) Kurz-Checkliste je Issue

* [ ] Milestone & Issue angelegt (mit vollständiger Beschreibung)
* [ ] Prompt (`*-impl.txt`) ans LLM → Patch als Diff
* [ ] `git apply` → `composer ci:test:php:unit` / `composer ci:test:php:phpstan`
* [ ] Falls rot: Fix-Loop mit Test-Output
* [ ] Branch/Commit/PR, PR-Text (`*-pr.txt`), Milestone + Issue verknüpfen

## 8) Troubleshooting

* **„git apply“ schlägt fehl** → prüfe, ob der Patch korrekt ohne Markup gespeichert wurde; ggf. `git apply --reject`.
* **LLM ändert zu viel** → Prompt enger („nur Datei A/B“, „keine API-Änderungen“).
* **Parser-DoS/Bounds** → in Issues harte Limits fordern und in Code prüfen.
* **XMP/XML-Sicherheit** → `LIBXML_NONET`, keine DTD/ENTITY-Auflösung.
