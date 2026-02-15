# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

**Please do NOT report security vulnerabilities through public GitHub issues.**

Instead, please report them via:
- **Email:** mail@ricosonntag.de (Rico Sonntag, Project Maintainer)
- **GitHub Security Advisories:** https://github.com/magicsunday/imagemeta/security/advisories/new

### Response Timeline

You should receive a response within **48 hours**. If the issue is confirmed, we will:
1. Acknowledge the vulnerability within **48 hours**
2. Release a security patch within **7 days** (for critical vulnerabilities) or **30 days** (for moderate vulnerabilities)
3. Credit you in the security advisory (unless you prefer to remain anonymous)

### Scope

ImageMeta is a **read-only parsing library** for image metadata. Vulnerabilities of interest include:

**In Scope:**
- Remote Code Execution (RCE) via malformed file parsing
- Denial of Service (DoS) via resource exhaustion (memory/CPU)
- XML External Entity (XXE) attacks in XMP parsing
- Path Traversal / Local File Inclusion when using file paths
- Information Disclosure via error messages or stack traces
- Buffer overflows or bounds-checking failures
- Deserialization vulnerabilities in maker notes parsing

**Out of Scope:**
- Vulnerabilities in applications consuming ImageMeta (not our responsibility)
- Social engineering attacks
- Physical attacks
- Issues requiring physical access to the server

### Security Features

ImageMeta implements multiple defense-in-depth security controls:

1. **XXE Protection:** All XML parsing uses `LIBXML_NONET | LIBXML_NO_XXE` flags
2. **DoS Protection:** Hard limits on IFD entries (10,000), payload sizes (8 MB), and nesting depth
3. **Bounds Checking:** All stream operations are bounds-checked to prevent buffer overflows
4. **No External Dependencies:** Zero composer dependencies reduces supply chain attack surface
5. **Streaming Architecture:** No full-file reads, minimizing memory exhaustion risks

### Known Non-Issues

The following are **design decisions**, not security vulnerabilities:

- **No authentication/authorization:** ImageMeta is a parsing library, not a web service
- **No encryption:** File content is not encrypted (metadata is inherently public in image files)
- **Error messages:** ParseError messages may contain file structure details (needed for debugging)

## Security Advisories

Published security advisories: https://github.com/magicsunday/imagemeta/security/advisories

---

Thank you for helping keep ImageMeta secure!
