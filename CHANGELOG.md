# Changelog

## 1.1.2 — Stable Prototype

- Added blocking PER Coding Style enforcement for PHP in CI.
- Added explicit Red Team regression tests for XSS and SQL injection, including hostile payload corpora.
- Added `CONTRIBUTING.md` with coding, security, testing, and pull-request requirements.
- Refined the SQL injection regression test to allow trusted local schema execution while continuing to reject user-controlled SQL construction.
- Synchronized README, application, frontend package, health endpoint, and release version metadata at 1.1.2.
- No product or cryptographic behavior changes: this release establishes and freezes the stable prototype baseline.

## 1.1.1 — Final Prototype

- Final CI/E2E stabilization with Python Playwright pinned to the published 1.62.0 release.
- Made the encrypted-link E2E locator role-specific and exact to avoid ambiguity with the local QR canvas.
- Added a narrowly scoped Firefox navigation retry for the Playwright 1.62 navigation flake, waiting for `load` before interaction.
- Synchronized application, frontend package, health endpoint, and release version metadata at 1.1.1.
- No new product features: this release closes the prototype validation phase.

## 1.1.0 — Prototype hardening

- HMAC-SHA-256 pseudonymization for rate-limit identities.
- Explicit trusted reverse-proxy CIDR handling and forwarded HTTPS support.
- Independent expiry cleanup utility and scheduling example.
- Structured privacy-preserving operational logs.
- Reproducible npm lockfile and pinned PHP Docker image/digest.
- Read-only/hardened Docker Compose runtime defaults.
- Local QR generation, Web Share support, countdowns, copy-message flow, and clearer one-time reveal semantics.
- Expanded randomized/tamper cryptographic tests and backend fuzz checks.
- 50-process one-time reveal concurrency test.
- Cross-browser Playwright E2E and accessibility smoke suite.
- Expanded security model, backup policy, proxy guidance, and manual accessibility checklist.

## 1.0.0 — Functional prototype

- Browser-side AES-256-GCM encryption and HKDF-SHA-256 key separation.
- PHP/SQLite backend with expiring and one-time encrypted messages.
- Basic security headers, validation, rate limiting, Docker deployment, CI, and documentation.

## 0.0.0 — Skeleton

- Initial repository/file structure without implementation.
