# GitHub Issues Creation - Instructions

## Problem

The script `create_issues.sh` may fail with error:
```
could not add label: 'refactoring' not found
```

This happens when the GitHub repository doesn't have the required labels yet.

## Solutions

We provide **three options** for creating the issues:

### Option 1: Automatic Label Creation (Recommended) ✅

Use the updated `create_issues.sh` which **automatically creates labels** first:

```bash
./create_issues.sh
```

**What it does:**
1. Creates all required labels with appropriate colors
2. Then creates all 11 issues with those labels
3. Shows progress for each step

**Requirements:**
- GitHub CLI (`gh`) must be authenticated
- You need permission to create labels in the repository

---

### Option 2: Without Labels (Fallback) 📋

If you don't have permission to create labels, use:

```bash
./create_issues_no_labels.sh
```

**What it does:**
1. Creates issues **without** labels
2. You can add labels manually later via GitHub web interface
3. Each issue description includes "Suggested Labels" section

**Advantage:** Works even without label creation permissions

---

### Option 3: Manual Creation 📝

Copy issue descriptions from `ISSUES_TO_CREATE.md` and create manually:

1. Go to: https://github.com/magicsunday/imagemeta/issues/new
2. Copy title and description from `ISSUES_TO_CREATE.md`
3. Add labels manually (if you have permission)
4. Repeat for all 11 issues

---

## Authentication

Ensure GitHub CLI is authenticated:

```bash
# Check authentication status
gh auth status

# If not authenticated, login:
gh auth login --web
```

---

## Labels Created

The script creates these labels:

| Label | Color | Purpose |
|-------|-------|---------|
| `refactoring` | Blue | Code refactoring tasks |
| `architecture` | Purple | Architectural changes |
| `priority:high` | Red | High priority |
| `priority:medium` | Yellow | Medium priority |
| `priority:low` | Green | Low priority |
| `SOLID:SRP` | Light Blue | Single Responsibility Principle |
| `SOLID:OCP` | Light Blue | Open/Closed Principle |
| `SOLID:DIP` | Light Blue | Dependency Inversion Principle |
| `DRY` | Orange | Don't Repeat Yourself |
| `KISS` | Orange | Keep It Simple, Stupid |
| `code-quality` | Cyan | Code quality improvements |
| `SoC` | Orange | Separation of Concerns |
| `GRASP:Polymorphism` | Light Blue | GRASP Polymorphism pattern |
| `testability` | Cyan | Testability improvements |
| `enhancement` | Light Cyan | Feature enhancements |
| `CoC` | Orange | Convention over Configuration |
| `configuration` | Cyan | Configuration-related |
| `YAGNI` | Orange | You Aren't Gonna Need It |
| `architecture-review` | Purple | Architecture review needed |
| `documentation` | Blue | Documentation |
| `user-facing` | Purple | User-facing changes |
| `easy-fix` | Green | Quick wins / easy fixes |

---

## Troubleshooting

### Error: "could not add label"

**Cause:** Labels don't exist yet
**Solution:** Use Option 1 (updated script) or Option 2 (no labels)

### Error: "HTTP 403: 403 Forbidden"

**Cause:** Not authenticated or insufficient permissions
**Solution:** 
```bash
gh auth login --web
```

### Error: "command not found: gh"

**Cause:** GitHub CLI not installed
**Solution:** Install GitHub CLI:
- macOS: `brew install gh`
- Linux: See https://github.com/cli/cli#installation
- Windows: See https://github.com/cli/cli#installation

---

## Issue Summary

The scripts create **11 issues** total:

**Critical Priority (3 issues):**
- #1: JpegParser refactoring (3-5 days)
- #2: IsoBmffParser context object (2-3 days)
- #3: ParsedExif domain adapters (5-7 days)

**High Priority (4 issues):**
- #4: MakerNotes decoder duplication (1 day) ⚡ Quick Win
- #5: XmpParser nested logic (2 days)
- #6: Parser dependency injection (3-4 days)
- #9: Replace instanceof with polymorphism (3-4 days)

**Medium Priority (3 issues):**
- #7: GpsConverter encoding decoders (1 day) ⚡ Quick Win
- #8: Configuration objects (2-3 days)
- #10: Evaluate factory necessity (2-3 days)

**Documentation (1 issue):**
- #11: Migration guide (ongoing)

---

## Next Steps After Creating Issues

1. **Review** all created issues in GitHub
2. **Assign** to appropriate developers
3. **Set milestones** if needed
4. **Start with Quick Wins**: Issues #4 and #7 (2 days total)
5. **Track progress** against the roadmap in FORENSIC_AUDIT.md

---

## Related Documentation

- `FORENSIC_AUDIT.md` - Complete audit report with all findings
- `ISSUES_TO_CREATE.md` - Full issue templates (if you need to create manually)
- `EXECUTIVE_SUMMARY_DE.md` - German executive summary
- `README_AUDIT.md` - Navigation guide for all audit documents
