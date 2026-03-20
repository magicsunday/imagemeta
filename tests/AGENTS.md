<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-03-02 -->

# AGENTS.md — MagicSunday/ImageMeta (tests)

## Overview

Test-only agent rules for a streaming metadata parser library. Governs `tests/` exclusively. Does not permit `src/` changes.

This `tests/AGENTS.md` applies only to work under `tests/` and **overrides** the root `AGENTS.md` where rules differ.

Task mode gate: this file applies only to **test-only tasks**. If a requested change requires `src/**` modifications, STOP and hand off to the root scope (`AGENTS.md`).

---

## 0. Rule Hierarchy

If rules conflict, the higher level wins:

1. **Absolute Test Rules (STOP on violation)**
2. **Specifications & Traceability**
3. **Test Design Rules**
4. **Naming & Structure**
5. **Domain Reference**

If unsure → **STOP and ask**.

---

## 1. Absolute Test Rules (Non-Negotiable)

### 1.1 Change Scope

* ❌ Do **not** modify files under `src/**`
* ❌ Do **not** change public APIs
* ❌ Do **not** relax guards or limits to make tests pass
* ❌ Do **not** introduce test-only behavior in production code
* ✅ Only commit files under `tests/**` (and only what is required)

If production code changes are required → **STOP**.

### 1.2 Git Rules

* Commit directly on `main`
* Commit message **must** match exactly:

```
GH-<number>: <Message starting with capital letter>
```

Example:

```
Add coverage for truncated IFD entries
```

### 1.3 Ticket Handling (Hard Rule)

* If a commit references a GitHub issue (`GH-<number>`)
* **and** `ci:test` completes fully green
* the referenced ticket **must be closed immediately**

No follow-up commits, no deferred closure, no “left open for review”.

### 1.4 Mandatory Verification Pipeline (Hard Rule)

* `ci:test` is the mandatory verification gate for ticket closure
* In this repository, `ci:test` includes dry-run checks for `ci:cgl` and `ci:rector`
* Any CGL/Rector changes detected by this gate **must be applied and committed**
* Re-run `ci:test` until it is fully green and no pending CGL/Rector diffs remain

Use the project Docker command pattern:

`docker compose run --rm -e COMPOSER_AUTH buildbox composer -d /var/docker/imagemeta <ci-command>`

Do not close tickets based on partial or targeted test runs.

---

## 2. Specifications (docs/) & Traceability

### 2.1 Source of Truth

Test expectations must be derived from the specifications stored under:

* `docs/**/*.html`
* `docs/**/*.pdf`

Tests must **not** encode undocumented assumptions.

### 2.2 HTML vs PDF

* Prefer **HTML** for locating specific tag/field definitions and “spec blocks”
* Prefer **PDF** for authoritative language and stable chapter numbering
* If HTML and PDF appear to disagree: treat **PDF as authoritative**
* If ambiguity remains → **STOP**

### 2.3 Spec Referencing in Tests

Where relevant, reference spec chapters in:

* a short test comment above the assertion, and/or
* a short comment above the test method describing the scenario

Example:

```php
// EXIF 3.0 §4.6.4 — SRATIONAL numerator sign and bounds
```

Do not copy long spec text. Keep references short and local.

---

## 3. Test Design Rules

### 3.1 Test Data

* Prefer **synthetic streams/blobs** over real media fixtures
* Explicitly craft cases for:

    * truncated payloads
    * invalid offsets
    * integer overflows / size wraparounds
    * malformed EXIF / TIFF / ISOBMFF structures
    * invalid box headers / invalid lengths
* Avoid real-world fixtures unless:

    * the issue is a documented real-world edge case, and
    * the fixture is minimal, and
    * licensing/privacy is clear

### 3.2 Positive + Negative Coverage

Every behavior under test must include:

* ✅ At least one **positive** test
* ✅ At least one **negative** test throwing:

    * `ParseError` or
    * `BoundsError`

No happy-path-only tests.

### 3.3 Failure Mode Quality

Negative tests must assert:

* the **exception type** (ParseError vs BoundsError), and
* the **triggering condition** via minimal setup (not via brittle internals)

Do not assert exact exception messages unless the message is part of the public contract.

### 3.4 Robustness vs Spec Strictness (Reader Rules)

* Tests must reflect **reader-side tolerance** (Postel’s Law)
* Do **not** encode writer-side “shall” requirements as reader failures
* If behavior is tolerated but non-standard, document it with a short test comment + spec reference

### 3.5 Determinism

* No time-based assertions unless time is injected/frozen
* No network
* No randomness unless seeded and justified
* No reliance on filesystem state beyond explicit temporary directories

---

## 4. Naming & Structure

### 4.1 Namespaces & Placement

* Test namespaces must mirror `src/` structure
* Place tests so that discovery is predictable
* Keep one test class per production class (unless explicitly justified)

### 4.2 Test Method Naming (Hard Rule)

* Test method names **must use CamelCase**
* Names must describe **observable behavior**, not implementation details
* Follow the existing project naming style in the same subtree
* Underscore-separated names are **forbidden**

**Correct:**

```
throwsBoundsErrorOnTruncatedIfdEntry
parsesBigTiffSrationalCorrectly
returnsNullWhenCaptureDateMissing
```

**Incorrect:**

```
it_throws_bounds_error_on_truncated_ifd_entry
testBounds
test_srational
```

### 4.3 Test Class Naming

* Follow existing project conventions (typically `<Subject>Test`)
* If uncertain, match the nearest existing test in the same subtree

---

## 5. STOP Conditions

STOP and ask if:

* expected behavior is unclear
* specs allow multiple interpretations
* a test would lock in behavior that cannot be justified by `docs/`
* a test would require weakening production guards
* writing a meaningful test requires changing `src/**`

---

## 6. Quality Bar

A test is acceptable only if:

* it fails on a broken implementation
* it guards against regression
* it does not rely on internal implementation details
* it can be explained by a spec reference or explicit tolerance note
* it is minimal (no unnecessary bytes, no unnecessary helpers)

---

## 7. Domain Reference (Non-Normative)

Reference-only reminders (do not override rules above):

* EXIF / Classic-TIFF & BigTIFF: offsets as uint64, inline limits, endianness correctness, SRATIONAL/RATIONAL handling
* ISOBMFF: box header/size validation, iloc extent sum, skip `data_reference_index != 0`
* XMP: strict non-network XML parsing, tolerate broken XML with partial results when safe
* Security: bounds checks are part of correctness; tests must cover DoS-style inputs (huge lengths, deep nesting) via small synthetic buffers

---

## Setup

See root `AGENTS.md §2.3` for CI execution. No additional setup beyond `composer install`.

## Build & Tests

```bash
make test
```

Run only PHPUnit: `composer ci:test:php:unit`

## Code style

* PHPUnit 12 attributes: `#[Test]`, `#[CoversClass]`, `#[UsesClass]`, `#[UsesTrait]`
* CamelCase test method names (see §4.2)
* `declare(strict_types=1)` in all test files
* `use function` imports for PHP built-ins

## Security

* Tests must cover DoS-style inputs (huge lengths, deep nesting) via small synthetic buffers
* Bounds checks are part of correctness — never weaken guards to make tests pass
* No network, no filesystem state beyond temp directories

## Checklist

- [ ] At least 1 positive + 1 negative test per behavior (`ParseError`/`BoundsError`)
- [ ] Synthetic data, not real-world fixtures (unless justified)
- [ ] Spec references where relevant (`EXIF 3.0 §4.6.4`)
- [ ] CamelCase test method names
- [ ] `ci:test` fully green

## Examples

**Good test name:** `throwsBoundsErrorOnTruncatedIfdEntry`
**Bad test name:** `it_throws_bounds_error_on_truncated_ifd_entry`

See existing tests in the same subtree for patterns to follow.

## When stuck

* Check specs in `docs/` (HTML for search, PDF for authoritative wording)
* Review existing tests in the same subtree for patterns
* If spec is ambiguous → **STOP and ask**
