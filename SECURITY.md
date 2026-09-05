# ECHO security model

ECHO is a prototype designed so that plaintext, the master secret, and derived decryption keys are processed in the browser rather than stored by the server. It has not undergone an independent security audit and should not be treated as a high-assurance system for critical secrets.

## Cryptographic design

- Every echo receives a random 256-bit master secret and random 128-bit identifier.
- HKDF-SHA-256 uses the identifier as salt and distinct `ECHO:encryption:v1` / `ECHO:access:v1` labels to derive separate encryption and reveal-authorization material.
- AES-256-GCM uses a fresh random 96-bit IV and authenticates `ECHO:v1:<id>` as Additional Authenticated Data.
- The complete share URL stores the identifier and master secret after `#`. Normal HTTP requests do not transmit URL fragments.
- The server stores ciphertext, IV, expiry metadata, burn policy, identifier, and only a SHA-256 hash of the independently derived access proof.
- A wrong key derives the wrong access proof and therefore cannot consume a one-time echo merely by knowing its identifier.

## Burn-after-reveal semantics

The user-facing option is deliberately described as **Burn after first reveal**. A one-time echo is selected and deleted inside one SQLite `BEGIN IMMEDIATE` transaction after successful authorization.

This guarantees the live database cannot successfully return the same one-time payload twice under normal concurrent access. The repository includes a 50-worker race test that requires exactly one successful reveal.

“Burned” does **not** mean ECHO can prove a human saw the plaintext. A network interruption, tab closure, browser crash, or decryption failure after authorized retrieval can leave the encrypted server copy consumed before the recipient actually reads it. ECHO intentionally prefers this simple one-shot semantic over a more complex two-phase acknowledgement protocol in the prototype.

SQLite `secure_delete` is enabled as defense in depth, but deletion is still an application-level guarantee rather than a promise of forensic erasure. WAL files, snapshots, storage layers, backups, or host-level copies may retain encrypted bytes.

## Rate-limit privacy

The rate limiter does not store raw client IP addresses or plain `SHA256(IP)` values. It stores:

```text
HMAC-SHA-256(ECHO_RATE_LIMIT_SECRET, client_ip)
```

A unique, private server secret is required for this to protect against offline enumeration of the IPv4 space. The Docker Compose fallback exists only to make local prototype startup easy and **must be replaced before public deployment**.

Generate one with:

```bash
openssl rand -hex 32
```

Changing this secret invalidates existing rate-limit buckets but does not affect encrypted echoes.

## Trusted reverse proxies

Forwarded headers are ignored by default. `X-Forwarded-For`, `X-Forwarded-Proto`, and `X-Forwarded-Host` are used only when the immediate peer address matches `ECHO_TRUSTED_PROXIES`.

The setting accepts comma-separated exact IPs or IPv4/IPv6 CIDRs, for example:

```text
ECHO_TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,2001:db8::/32
```

The prototype supports the common **single trusted reverse-proxy hop** model. Do not copy broad network ranges from examples without understanding the actual Docker/host network. Incorrect trust configuration can allow spoofed client identity or scheme information.

## HTTPS and server-delivered JavaScript

Serve ECHO only over HTTPS when it is reachable across an untrusted network. Without HTTPS, an active attacker can replace the JavaScript and steal plaintext or keys.

HTTPS does not solve the stronger web-E2EE limitation that the same server delivering the application also delivers the JavaScript performing encryption. A compromised or malicious ECHO server could serve modified code that exfiltrates plaintext or secrets. A future high-assurance design would require independently verifiable/signed client software or another integrity mechanism.

## Metadata and endpoint limitations

ECHO hides message content from an honest server, but it is not an anonymity system. Infrastructure can still observe metadata such as request timing, ciphertext size, expiry choice, burn policy, and network addresses before pseudonymization in the application database.

ECHO cannot protect plaintext from:

- a compromised browser or operating system;
- malicious browser extensions;
- screenshots or screen recording;
- clipboard history or clipboard-sync services;
- a recipient who copies the plaintext;
- malicious JavaScript served by a compromised hosting environment.

The complete share link is a bearer secret. Anyone who obtains it can derive the reveal proof and decryption key.

## Logging and observability

`ECHO_LOG_LEVEL` accepts `off`, `error`, or `info`. Structured logs are deliberately minimal and do not intentionally include:

- plaintext;
- ciphertext;
- echo IDs;
- access proofs or hashes;
- master secrets;
- complete share URLs;
- raw client IP addresses.

Events contain only operational information such as action type, status, TTL/burn policy where relevant, and coarse request duration. No analytics or remote telemetry service is included.

## Expiry cleanup

Expiry is enforced on reveal and stale rows are opportunistically cleaned during normal activity. For idle deployments, run:

```bash
php backend/bin/cleanup.php
```

periodically with cron/systemd or another scheduler. The command removes expired echoes and old rate-limit rows and performs a passive WAL checkpoint.

## Backup policy

For the prototype, the recommended policy is **do not back up the transient SQLite message database**. Source code and configuration should be backed up separately.

If an operator nevertheless snapshots `/var/lib/echo`, deleted one-time ciphertext may persist in that snapshot until the backup itself is destroyed. Because the master secret is never intentionally stored server-side, such bytes should remain encrypted, but this is weaker than claiming that every physical copy has disappeared.

## Container and deployment hardening

The reference image pins a specific PHP patch release and image digest. Compose uses a read-only root filesystem, a dedicated writable data volume, temporary runtime filesystems, `no-new-privileges`, and conservative resource/process limits. These settings reduce accidental exposure but are not a substitute for host patching or network controls.

## Testing expectations

The repository includes:

- strict TypeScript checks;
- cryptographic round-trip/property and tamper tests;
- backend validation and fuzz checks;
- trusted-proxy CIDR/header tests;
- HMAC rate-limit storage checks;
- SQLite repository integration tests;
- a 50-process burn-after-reveal race test;
- cross-browser Playwright E2E tests for Chromium, Firefox, and WebKit;
- keyboard/focus/accessibility smoke checks.

Automated accessibility smoke tests do not replace manual testing with assistive technologies such as NVDA or VoiceOver.

## Reporting a vulnerability

Do not publish real secrets, complete ECHO links, or exploit details in a public issue. Report vulnerabilities privately to the project owner and use non-sensitive test data in reproductions.
