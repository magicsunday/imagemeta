# AGENTS.md — MagicSunday/ImageMeta

> Guide for LLM agents (Codex/Copilot/ChatGPT etc.) in this repo.
> **Goal:** reproducible, safe, and lean **pull requests** with tests, static analysis, and clear guardrails.
> **Note:** **No unified-diff patches** are expected (workflow: **Branch → Commits → PR**).

---

## 1) Scope & Principles

**Project goal:** Streaming parser for JPEG/HEIC/MOV/MP4; EXIF (Classic-TIFF & BigTIFF), XMP, QuickTime/ISOBMFF — **pure PHP 8.4**, no `exif_read_data()` and no external CLI tools.

**EXIF support (complete):** EXIF **1.x**, **2.x** (2.1/2.2/2.21/2.3/2.31/2.32) and **3.0** (endianness, Classic-TIFF `0x2A`, BigTIFF `0x2B`/64-bit).
**References in repo:**

* `docs/EXIF-210.pdf`, `docs/EXIF-220.pdf`, `docs/EXIF-230.pdf`, `docs/EXIF-231.pdf`, `docs/EXIF-232.pdf`, `docs/EXIF-300.pdf`
* TIFF 6.0: `docs/TIFF6.pdf`

**Principles (MUST):**

* **Streaming only:** `Core\Stream`/`StreamWindow` — no full file reads.
* **Security:** hard bounds checks; max limits for lengths/offsets; XMP strictly with `LIBXML_NONET` (no DTD/entities, no network I/O).
* **Code quality:** `declare(strict_types=1);`, PSR-12, **no** `mixed`, **no** `empty()`.
* **Compatibility:** minimally invasive; no API breaks without changelog.
* **Structure & docs:** exactly **one class per file**; descriptive identifiers; **English** PHPDoc blocks (purpose/params/return) and **English** inline comments at complex places.
* **Tests & static:** PHPUnit 12 (attributes), PHPStan (max level), PHPCS; **coverage target ≥ 90 %**.
* **Namespace mirrors:** tests follow `src/` structure (e.g. `…\Parse\IsoBmff` → `…\Tests\Parse\IsoBmff`).
* **Models/VOs:** parsing outputs **value objects** (sensibly grouped) with fluent access/chaining.

**Traceability (Specs):**

* In parsers **at the relevant code location** reference the applicable EXIF chapter via PHPDoc/inline comment (format: `EXIF 3.0 §<chapter>`).
* **If multiple versions apply:** always name the **latest** and also all differing chapters in older versions with relevant changes (e.g. “EXIF 3.0 §4.6.4; EXIF 2.32 §…”).
* **If already covered:** only add if necessary (missing/imprecise/outdated).
* **Unclear implementation details:** always consult the **HTML specifications under `docs/`** before making assumptions or changes.
* **Tag definitions:** always describe and name tags using the **corresponding specification block** from the HTML specs in `docs/`.

**Enums for common encodings (native PHP backed enums):**

* **Character encoding:** `ASCII`, `UTF8`, `UTF16BE`, `UTF16LE`
* **Endianness (TIFF):** `II` (little), `MM` (big)
* **EXIF/IFD areas:** `IFD0`, `ExifIFD`, `GPSIFD`, `InteropIFD`, `MakerNotes`
* **XMP container:** `Alt`, `Bag`, `Seq`
* **ISOBMFF construction:** `FileOffset`, `Offset64`, `ItemOffset` (if relevant)
* **Other stable types:** e.g. `ColorSpace`, `Orientation`, `Compression`
  **Goal:** fewer magic numbers/strings, clearer contracts, better type safety.

---

## 2) Agent Roles

| Agent           | Responsibility                                                                                         | In/Out                                     |
| --------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------ |
| **Planner**     | Read issue/milestone; define file scope, non-goals, guardrails.                                       | In: Issue; Out: Sub-tasks + file scope     |
| **Spec Writer** | Define acceptance criteria (AC) & test cases (incl. red/failure modes).                               | In: Planner; Out: Test specification       |
| **Test Agent**  | **RED**: write PHPUnit tests first; synthetic streams/blobs; negative paths (ParseError/BoundsError). | In: Spec; Out: Commits under `tests/**`    |
| **Implementer** | **GREEN**: implement only in file scope; respect streaming & guards; **add specs/enums/docs**.        | In: red tests; Out: green commits          |
| **Static/QA**   | PHPStan/PHPCS green; small refactors without semantic change.                                         | In: linter/stan output; Out: commits       |
| **Security**    | Security review: lengths/offsets, XML flags, DoS mitigation.                                          | In: PR diff; Out: review notes/mini commits|
| **Reviewer**    | Minimality, readability, **spec refs**, enums, AC coverage, DX.                                       | In: PR; Out: review comments/mini commits  |
| **Release**     | PR text, changelog, labels/milestone, “Closes #…”, tagging.                                           | In: final PR; Out: release/tag             |

> Roles are **checklists**; one person may hold multiple roles.

---

## 3) Standard Tools & Commands

* **Runtime:** PHP 8.4
* **Tests:** `composer ci:test:php:unit`
* **Coverage:** `composer ci:test:php:unit:coverage` (target ≥ **90 %**)
* **Static:** `composer ci:test:php:phpstan`
* **CPD (dupes):** `composer ci:test:php:cpd` or `npx jscpd --config .build/.jscpd.json`
* **CGL/Style:** `composer ci:cgl` (apply formatting changes)

**Git Flow (no diffs):**

* Branch scheme: `feat/<area>-<slug>`, `fix/<area>-<slug>`, `chore/<area>-<slug>`
* Commits: **Conventional Commits** (e.g. `feat(tiff): add BigTIFF SRATIONAL reader`)
* Open PR, keep CI green, assign reviewer.

---

## 4) Guardrails (for **all** changes)

**General**

* **Only** change files approved in the issue.
* **No** external binaries/extensions; **no** `exif_read_data()`.
* Hard **max limits** for segment/box/packet lengths; always bounds-check; **no network I/O**.

**TIFF/EXIF**

* Endianness strict (Classic `0x2A`, BigTIFF `0x2B`/64-bit).
* Inline values: ≤ 4 bytes (Classic) / ≤ 8 bytes (BigTIFF).
* `RATIONAL/SRATIONAL` correct; GPS sign from Ref tags (S/W negative).
* **Specs required:** new/changed parser branches **with chapter citation** (see “Traceability (Specs)”).

**ISOBMFF/QuickTime**

* Box size ≥ header; 32/64-bit sizes validated correctly.
* `iloc` extents **sum**; absolute offsets only if `constructionMethod=0` **and** `data_reference_index=0`.
* `data_reference_index ≠ 0` **skip** (no remote refs/network).

**XMP**

* JPEG: APP1 prefix `http://ns.adobe.com/xap/1.0/\0`.
* ISOBMFF: `uuid` **BE7ACFCB-97A9-42E8-9C71-999491E3AFAC** or item `application/rdf+xml`.
* `XMLReader` + `LIBXML_NONET`; **no** DTD/entities; Alt/Bag/Seq → arrays.

**Error handling**

* Parser errors must throw **`ParseError`** or **`BoundsError`** only.
* No warnings/notices as control flow.

---

## 5) Pipeline (Agent Playbook)

1. **Planner** — define file scope, non-goals, guardrails; reference `prompts/`.
2. **Spec Writer** — define **AC** & **test cases** (positive/negative); name “red” clearly.
3. **Test Agent (RED)** — only commit under `tests/**`; synthetic streams/blobs instead of large fixtures.
4. **Implementer (GREEN)** — minimal change in file scope to make tests pass; streaming & guards;
   **add/check**: *spec chapter references*, *docblocks*, *descriptive variables*, *inline comments* (complex spots), *enums* (common encodings).
5. **Static/QA** — PHPStan/PHPCS green; small cleanups without semantic change.
6. **Security** — verify lengths/offsets/XMP flags; minimize DoS surface.
7. **Reviewer & Release** — review for minimality/DX/AC **incl. spec refs & enums**; PR text, changelog, labels, “Closes #…”, tag.

---

## 6) Prompt Templates

**Implementation (per issue)**

```
Role: Implementer. Fulfill issue “<TITLE>”.
Context: PHP 8.4, strict_types=1, PSR-12, streaming parser.
File scope: <List of allowed files>
Guards: Bounds checks, max sizes, LIBXML_NONET, no external tools.
Docs: Add/check EXIF chapters (latest + differing older),
full docblocks, descriptive variables, inline comments (complex spots).
Enums: Introduce/use enums for common encodings (Encoding/Endianness/IFD/XMP …).
Output: Commits (Conventional Commits) on branch + Pull Request.
```

**Tests first**

```
Role: Test Agent. Write only PHPUnit tests for “<TITLE>”.
Use synthetic streams/blobs; cover negative paths (ParseError/BoundsError).
Validate spec version differences (2.3x vs. 3.0) with targeted tests.
Output: Commits under tests/**; summarize CI output briefly.
```

**Fix loop**

```
Role: Implementer. PHPUnit output (red):
<OUTPUT>
Provide minimal code change in the approved file scope to fix failures;
update spec refs/enums/docblocks/comments if needed; commit conventionally.
```

**PR text**

```
Role: Release. Write PR description (overview, details, tests, risks, changelog, “Closes #…”).
List changed/new EXIF chapters (version/§) in section “References”.
```

---

## 7) Domain Cheat Sheet

* **EXIF/BigTIFF:** Inline limits; `RATIONAL/SRATIONAL` correct; GPS ref → sign; offsets as `uint64`.
  *Enums:* `Endianness`, `IfdKind`, `ExifType`, `CharacterEncoding`.
* **ISOBMFF:** Header/size valid; 32/64-bit lengths; `iloc` extents sum; no remote refs.
  *Enums:* `ConstructionMethod`, optional `BoxType` (string-backed if stable).
* **XMP:** Verify signatures; no network/DTD; tolerate broken XML (partial results, no fatals).
  *Enums:* `XmpContainer` (`Alt|Bag|Seq`).
* **Security:** Hard limits, exceptions not warnings; absolutely no network I/O.

---

## 8) Definition of Done (DoD)

* ✅ PHPUnit **green** (incl. negative cases)
* ✅ **Coverage ≥ 90 %** (`ci:test:php:unit:coverage`)
* ✅ PHPStan **green** (at least changed files)
* ✅ PHPCS/CGL **green**; formatting changes applied
* ✅ **CPD** no relevant duplicates
* ✅ Changes **minimal** and in file scope
* ✅ AC met; README/Changelog/PR text updated
* ✅ **Spec refs** (latest + differing older versions) added/verified
* ✅ **Enums** for common encodings present/used (where sensible)
* ✅ Issue/milestone linked; PR with **Conventional Commits** & **“Closes #…”**

---

## 9) Example Card — “M3: ISOBMFF finish”

* **Input:** Issue describes EXIF/XMP extraction from ISOBMFF (Exif box **or** `iloc` item), incl. error cases.
* **File scope:**

    * `src/Parse/IsoBmff/IsoBmffExtractor.php`
    * `src/Parse/IsoBmff/BoxGuards.php` (if needed)
    * `tests/Parse/IsoBmff/**`
* **Guards:** Box size ≥ header; offsets/lengths in `uint64`; extent sum ≤ file size; `data_reference_index ≠ 0` → skip.
* **Expectation:** EXIF via Exif box **or** `iloc`; XMP via `uuid`/item; `content.identifier` extracted; corrupt → `ParseError`.
* **Docs:** Parser spots for `iloc`/`uuid`/`keys` **with EXIF/ISOBMFF chapters**; enums for `constructionMethod`/`dataRef`.
* **Output:** Branch `feat/isobmff-fixes`, commit series (RED → GREEN → QA), PR with CI green.

---

## 10) Common Mistakes & Countermeasures

* **Change scope too broad** → enforce file scope in prompt.
* **Full-file reads** → reject; require streaming.
* **Missing guards** → add negative tests for corrupt lengths/offsets.
* **Fragile XMP parser** → `LIBXML_NONET`, no DTD/entities, tolerate broken XML.
* **Magic numbers/strings** → use constants/enums.
* **Missing spec refs** → add chapter refs on parser changes (latest + relevant older).
* **Assumptions without spec checks** → consult HTML specs in `docs/` before implementing unclear behavior.
* **Tag names not aligned to spec blocks** → align tag naming/description with the exact HTML spec block.
* **Unnecessary control-flow leftovers** → remove redundant `return`/`continue` (see coding rules).

---

## 11) Pre-Commit Checklist

* [ ] Only approved files changed
* [ ] Streaming preserved; no unintended buffering/full reads
* [ ] Hard bounds checks & max limits present
* [ ] Tests (incl. negative) written/updated; **coverage ≥ 90 %**
* [ ] PHPStan/PHPCS **green**; **CPD** no relevant dupes
* [ ] No `mixed`, no `empty()`, no nested ternaries
* [ ] Sensible **constants/enums** instead of magic numbers/strings
* [ ] **Docblocks** complete; **descriptive names**; **inline comments** at complex spots
* [ ] **EXIF chapters** correctly referenced (latest + relevant older)
* [ ] **Conditions parenthesized:**

    * **Example (with parens):** `if (($this->ifd1 instanceof Ifd) && ($ifd === $this->ifd1)) { … }`
    * **Exceptions (no extra parens ok):** `if ($value !== null) …`, `if (is_array($value)) …`
* [ ] **Redundant `return`/`continue` removed** where safe and without semantic change
* [ ] Interfaces used where contracts make sense
* [ ] Conventional Commits; PR with “Closes #…”

---

## 12) Compliance Catalog (review existing implementation & adjust if needed)

**Implementation scope**

* [ ] EXIF 1.x/2.x/3.0 compliant (`docs/EXIF-*.pdf`)
* [ ] TIFF 6.0 considered (`docs/TIFF6.pdf`)
* [ ] PHP 8.4+ features/compatibility
* [ ] KISS, SOLID, DRY, YAGNI, GRASP, LoD, SoC, CoC adhered to

**Build/CI duties before every commit**

* [ ] `composer ci:test:php:unit:coverage` **green** (≥ 90 %)
* [ ] `composer ci:test:php:phpstan` **green** (at least changed files)
* [ ] `composer ci:test:php:cpd` or `npx jscpd --config .build/.jscpd.json` **green**, no dupes
* [ ] `composer ci:cgl` run, formatting changes applied

**Coding**

* [ ] Sensible **interfaces** used
* [ ] No `@deprecated` – removed when obsolete
* [ ] Tests for **every** class (incl. negative cases)
* [ ] No `mixed` types, no `empty()` calls
* [ ] **`array_find()`**, **`array_any()`**, **`array_all()`**, `array_filter`, `array_map` used where appropriate
* [ ] **Typed class constants**
* [ ] Redundant casts/default arguments removed
* [ ] No `{}` for simple string interpolation
* [ ] No nested ternaries
* [ ] Null-pointer risks handled
* [ ] FQN/imports used sensibly (`use`, `use function`, `use const`)
* [ ] Classes `readonly` when possible; redundant `readonly` removed
* [ ] Static methods **not** via `->`
* [ ] Redundant/unused methods/classes removed
* [ ] **One class per file**
* [ ] **PHPDoc blocks for classes and methods** (English, complete)
* [ ] **Inline comments at relevant code locations** (English, explanatory)
* [ ] Test namespaces mirror `src/` structure
* [ ] Variables/constants **descriptively** named
* [ ] **Enums** for common encodings present/used
* [ ] **EXIF chapters** referenced/updated at relevant code locations
* [ ] **Specs consulted** (HTML under `docs/`) for unclear behavior before implementation
* [ ] **Tag definitions** aligned with the corresponding HTML spec block
* [ ] **Explicit parentheses in conditions:**

    * **Example (with parens):** `if (($this->ifd1 instanceof Ifd) && ($ifd === $this->ifd1)) { … }`
    * **Exceptions (no extra parens ok):** `if ($value !== null) …`, `if (is_array($value)) …`
* [ ] **Redundant `return`/`continue` removed**, when safe and without semantic change

---

**Owner/Contact:** *MagicSunday* (Europe/Berlin)
**Structure:** `src/Core`, `src/Detect`, `src/Parse/{Jpeg,IsoBmff,Tiff,Xmp}`, `src/Model`, `src/Convenience`, `src/MakerNotes`, `tests/**`, `prompts/**`, `docs/**`.
