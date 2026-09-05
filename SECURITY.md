# ECHO security model

ECHO is designed so that plaintext, the master secret, and derived decryption keys are processed in the browser, not by the server.

## Cryptographic design

- A random 256-bit master secret is generated for every echo. HKDF-SHA-256 uses the random 128-bit echo ID as salt and distinct `ECHO:encryption:v1` / `ECHO:access:v1` info labels to derive separate cryptographic material.
- The encryption subkey is AES-256-GCM with a fresh random 96-bit IV. The independent 256-bit access proof is derived through the separate HKDF info label; only a SHA-256 hash of that proof is stored by the server.
- The echo identifier is authenticated as AES-GCM additional authenticated data (`ECHO:v1:<id>`), preventing ciphertext from being transplanted to another identifier.
- The share URL stores the identifier and master secret in the URL fragment (`#...`). URL fragments are not sent in HTTP requests.
- The server receives only the ciphertext, IV, expiry, burn policy, identifier, and SHA-256 hash of the HKDF-derived access proof at creation time.
- Reveal requires the HKDF-derived access proof. The backend stores only a SHA-256 hash of that proof and compares it before returning ciphertext. An identifier alone cannot consume a one-time echo, and a corrupted master secret derives the wrong proof instead of burning the message.

## Burn-after-reading semantics

A burn-after-reading echo is selected and deleted within a single SQLite `BEGIN IMMEDIATE` transaction. A wrong access proof does not consume the echo. SQLite `secure_delete` is enabled, but row deletion is still a logical application guarantee rather than a promise of forensic erasure: WAL files, filesystem snapshots, storage layers, or backups may retain encrypted bytes. The UI uses an explicit POST reveal action, so ordinary link preview crawlers do not fetch or burn the payload.

## Operational requirements

- Serve ECHO over HTTPS in production. Without HTTPS, an active network attacker can replace the JavaScript and steal plaintext or keys.
- Keep the server and browser environment patched.
- Do not add third-party scripts, analytics, external fonts, or broad CSP exceptions without revisiting the threat model.
- The complete share link is a bearer secret. Anyone who obtains it can reveal the message.
- ECHO does not protect plaintext on a compromised endpoint, from screenshots, clipboard history, browser extensions, or a malicious server that serves modified frontend code.

## Server-side protections

The backend applies strict JSON validation, exact-origin checks for browser requests, request-size limits, hashed-IP rate limiting, generic not-found responses, prepared SQL statements, no cookies, no sessions, no third-party network calls, and restrictive security headers.

## Reporting a vulnerability

Do not publish secrets, real encrypted links, or exploit details in a public issue. Report vulnerabilities privately to the project owner and include a minimal reproduction using non-sensitive test data.
