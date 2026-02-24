# AGENTS.md — MagicSunday/ImageMeta

**Purpose:** Deterministic, minimal, and safe code changes by LLM agents.
**Scope:** This file defines **hard technical rules**. It is not process documentation.

**Workflow:**
✔ Commits are made **directly on `main`**
✔ Commit message format is mandatory
✔ Tickets are closed automatically after successful CI (see §1.5)

**Issue Authority:**
Issues created via `.github/ISSUE_TEMPLATE/agent-task.yml`
contain the **authoritative task instructions** for agents
(scope, acceptance criteria, specs, completion rules).

If there is a conflict between an issue description and assumptions made by the agent:
**the issue content wins**.

---

## 0. Rule Hierarchy (Conflict Resolution)

If rules conflict, the higher level wins:

1. **Absolute Rules (STOP on violation)**
2. **Specifications & Traceability**
3. **Security & Streaming Guarantees**
4. **Architecture & Design Rules**
5. **Domain Reference**

If unsure → **STOP and ask**. Never guess.

---

## 1. Absolute Rules (Non-Negotiable)

### 1.1 Scope & Safety

* ❌ No parser/runtime logic that requires full-buffer materialization — parsing must remain **streaming-based**
* ✅ Agents may read local repository files as needed for analysis and edits
* ❌ No network I/O (incl. XML, ISOBMFF refs)
* ❌ No external binaries, extensions, or `exif_read_data()`
* ❌ No speculative abstractions or unused hooks
* ❌ No assumptions without spec verification

### 1.2 Change Scope

* Modify only the **minimal set of files required** to satisfy the task correctly
* If additional files outside the obvious scope are required, **STOP** and ask for confirmation with a short rationale

### 1.3 API Stability

* No public API changes unless explicitly instructed

### 1.4 Git Rules

* Branching is **not used**
* Commit directly on `main`
* Commit message **must** match exactly:

```
GH-<number>: <Message starting with capital letter>
```

Example:

```
GH-421: Fix BigTIFF SRATIONAL sign handling
```

### 1.5 Ticket Handling (Hard Rule)

* If a commit references a GitHub issue (`GH-<number>`)
* **and** `ci:test` completes fully green
* the referenced ticket **must be closed immediately**

No follow-up commits, no deferred closure, no “left open for review”.

### 1.6 CI Dry-Run Changes (Hard Rule)

* `ci:test` is the mandatory gate and includes dry-run checks for `ci:cgl` and `ci:rector`
* Any `ci:cgl` / `ci:rector` changes detected by this gate **must be applied and included** in the same ticket work
* A ticket is only considered done when `ci:test` is green **and** no pending CGL/Rector diffs remain

---

## 2. Project Context (Hard Constraints)

* **Language:** PHP 8.4 (`declare(strict_types=1);`)
* **Domain:** Streaming metadata parser (JPEG, TIFF/EXIF, ISOBMFF/QuickTime, XMP)
* **Parser type:** Reader-only (Postel’s Law applies)
* **Structure:** exactly **one class per file**
* **Style:** PSR-12
* **Forbidden:** `mixed`, `empty()`, nested ternaries
* **Docs:** English PHPDoc + English inline comments at complex logic
* **Static:** PHPStan max level, PHPCS

---

## 3. Specifications (docs/) & Traceability (Hard Rule)

### 3.1 Source of Truth

All format behavior must be derived from the specifications stored under:

* `docs/**/*.html`
* `docs/**/*.pdf`

If behavior is not clearly implied by existing code/tests, **consult `docs/` first**.

### 3.2 HTML vs PDF (What to Read)

* Prefer **HTML** for fast navigation/search and exact spec blocks (tags, tables).
* Prefer **PDF** for authoritative wording and stable chapter numbering.
* If HTML and PDF disagree: **PDF is authoritative**, add a short inline comment noting the discrepancy.

### 3.3 Mandatory Traceability

Before implementing or changing parser behavior that is not obviously implied by existing code/tests:

1. Consult the relevant spec document(s) in `docs/`
2. Add or update a nearby code comment or PHPDoc reference

**Required citation format (at relevant code location):**

* `EXIF <version> §<chapter>`
  Examples: `EXIF 3.0 §4.6.4`, `EXIF 2.32 §3.3`

### 3.4 Version Rule

If multiple spec versions apply:

* Cite the **latest** version, and
* Also cite **older differing** chapters that matter

### 3.5 Tag Definitions Rule

When introducing, naming, or describing tags:

* Align names and descriptions with the corresponding **HTML spec block** in `docs/`
* Do not invent semantics without spec backing

### 3.6 Unclear Specs

If the spec is ambiguous:

* **STOP**
* Do not guess or silently choose an interpretation
* If needed, propose alternatives and describe which tests would lock each in

---

## 4. Security & Streaming Guarantees

Always enforce:

* Hard bounds checks on offsets, sizes, counts
* Explicit maximum limits for all segments/boxes/packets
* XML parsing via `XMLReader` + `LIBXML_NONET`
* No DTD/entities and no network resolution
* Errors via **exceptions only**:

    * `ParseError`
    * `BoundsError`
* No warnings/notices as control flow

### 4.1 ParseError Code Ranges

Every `new ParseError(…, CODE)` must use a **unique** numeric code.
Codes are assigned per module in these ranges:

| Range | Module | Location |
|-------|--------|----------|
| 1001–1030 | Core / Stream | `src/Core/` |
| 1031–1033 | Format Detection | `src/Detect/` |
| 1034–1119 | MakerNotes | `src/MakerNotes/` |
| 1120 | MetadataReader | `src/MetadataReader.php` |
| 1123–1141 | ICC | `src/Parse/Icc/` |
| 1127–1135 | IPTC | `src/Parse/Iptc/` |
| 1140–1606 | ISO BMFF / QuickTime | `src/Parse/IsoBmff/` |
| 1263–1334 | JPEG | `src/Parse/Jpeg/` |
| 1301–1853 | TIFF / EXIF / DNG | `src/Parse/Tiff/` |
| 1854–1857 | JPEG config | `src/Parse/Jpeg/JpegParserConfig.php` |
| 1858–1868 | Value-object validation | `src/MakerNotes/`, `src/Exif/Model/` |
| 1869–1942 | Disambiguated duplicates | various (added by GH-1609) |
| 1943–1951 | Assembler limits & config validation | various (added by GH-1621, GH-1626) |

**Rules:**
* New codes: use `max + 1` (currently **1964**).
* Each code must be globally unique across all `src/` files.
* Overlapping ranges are historical; do not extend them further.

---

## 5. Architecture & Design Rules (Operational)

### 5.1 KISS

* Prefer explicit logic over generic frameworks
* ≤2 call sites → no helper extraction

### 5.2 DRY

* Extract only after **3+ real duplicates**
* Prefer constants/enums over helpers

### 5.3 YAGNI

* No unused parameters, flags, stubs, or future formats

### 5.4 SOLID

* **SRP:** one reason to change per class
* **DIP:** depend on abstractions (`Stream`, parser interfaces)
* **ISP:** interfaces must stay minimal

### 5.5 GRASP

* Parsers parse, models hold data
* Controllers orchestrate, not decode
* Factories create; models never parse

### 5.6 Law of Demeter

Allowed:

```
$result->ifd0?->get(ExifTag::MAKE)?->value
```

Forbidden:

```
$parser->getStream()->getBuffer()->seek()
```

### 5.7 Separation of Concerns

* Detect ≠ Parse ≠ Model ≠ Convenience
* No EXIF logic inside container detection

### 5.8 Convention over Configuration

* Follow existing folder, enum, naming, and test patterns
* No new configuration unless unavoidable

---

## 6. Decision Logic (STOP Conditions)

STOP and ask if:

* Spec behavior is ambiguous (see §3.6)
* EXIF versions conflict without guidance
* Change exceeds the minimal required file scope or explicit user constraints
* Design tradeoff cannot be justified with evidence
* Guards would need to be relaxed to “make it pass”

Never guess. Never infer. Never silently change semantics.

---

## 7. Domain Reference (Non-Normative)

The following topics are reference-only and do not override the rules above:

* EXIF / Classic-TIFF & BigTIFF rules
* ISOBMFF / QuickTime rules
* XMP handling rules
* Enums & constants guidance
* Postel’s Law (reader robustness with documented deviations)

---

**Owner/Contact:** MagicSunday (Europe/Berlin)
