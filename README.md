# E.C.H.O. 🔐
**E**phemeral **C**ryptographic **H**andoff **O**nline

A lightweight, self-hostable prototype for securely sharing end-to-end encrypted, expiring messages. ECHO encrypts and decrypts entirely in the browser, so the backend stores ciphertext while the 256-bit master secret remains only in the shared URL fragment and is never transmitted to the server.

## 🚀 Core Features
### 1. End-to-End Client-Side Encryption
* **AES-256-GCM Encryption:** Messages are encrypted locally with the browser-native Web Crypto API, providing confidentiality and authenticated integrity without custom cryptographic primitives.
* **HKDF-SHA-256 Key Separation:** Every echo starts from a random 256-bit master secret. Independent encryption and access material are derived with HKDF-SHA-256 using the echo identifier as salt and separate domain labels.
* **Authenticated Context Binding:** The echo ID is authenticated as Additional Authenticated Data (AAD), preventing ciphertext from being silently transplanted between different message identifiers.

### 2. Ephemeral & One-Time Message Delivery
* **Burn After First Reveal:** One-time echoes are selected and deleted inside a single SQLite `BEGIN IMMEDIATE` transaction. A successful server retrieval consumes the live encrypted copy, and a second reveal returns nothing.
* **Configurable Expiration:** Messages automatically expire after 5 minutes, 1 hour, 1 day, or 7 days, whether or not they have been opened.
* **Preview-Safe Flow:** Merely opening a share URL does not fetch or consume the message. The recipient must explicitly select **Reveal echo**, avoiding accidental destruction by normal link-preview crawlers.
* **Independent Cleanup:** `backend/bin/cleanup.php` removes expired echoes and stale rate-limit buckets even when the application is otherwise idle.

### 3. Privacy-Oriented Security Architecture
* **Server-Blind Secret Handling:** The master secret is stored after the URL `#`, which normal HTTP requests do not send to the server. The backend therefore receives ciphertext and metadata, but not the secret required for decryption.
* **Protected Reveal Authorization:** Knowing an echo ID is insufficient to retrieve or destroy a message. A separate HKDF-derived access proof is checked before ciphertext is returned.
* **Pseudonymous Rate Limiting:** Client addresses are transformed with `HMAC-SHA-256(server_secret, IP)` before storage rather than a reversible-in-practice plain hash of the small IPv4 space.
* **Minimal Tracking Surface:** ECHO uses no accounts, cookies, local storage, analytics, trackers, external fonts, or third-party network scripts. Structured application logs intentionally omit message IDs, raw IPs, ciphertext, plaintext, keys, and share URLs.

### 4. Prototype-Grade Reliability & Validation
* **Concurrency Test:** A dedicated integration test starts 50 simultaneous attempts to reveal one one-time echo and requires exactly one success.
* **Cryptographic Property Tests:** Randomized Unicode round trips and tamper tests cover altered keys, IDs, IVs, and ciphertexts.
* **API Fuzz Checks:** The backend test suite exercises invalid scalar types, malformed identifiers, unexpected fields, limits, origins, and proxy CIDRs.
* **Cross-Browser E2E:** Playwright tests cover Chromium, Firefox, and WebKit for creation, one-time reveal, reusable reveal, malformed links, QR rendering, keyboard focus, and basic accessibility semantics.

### 5. Practical Self-Hosted UX
* **Local QR Codes:** Share-link QR codes are generated entirely in the browser with a vendored MIT-licensed encoder; the secret is not sent to another service.
* **Native Mobile Sharing:** Browsers supporting the Web Share API expose a direct **Share** action.
* **Expiry Countdown:** Created and reusable echoes display live expiry information.
* **Clear Security State:** The UI distinguishes local encryption, one-time reveal behavior, remaining lifetime, and the exact limitation that “burned” means consumed on successful retrieval rather than proof of human reading.

### 6. Hardened but Simple Deployment
* **SQLite Persistence:** WAL mode, transactional one-time reveal behavior, prepared statements, request-size limits, strict JSON validation, exact-origin checks, and defensive security headers keep the backend compact and auditable.
* **Trusted Reverse Proxy Support:** Explicit IP/CIDR allowlisting controls when `X-Forwarded-For`, `X-Forwarded-Proto`, and `X-Forwarded-Host` are trusted. Untrusted clients cannot spoof these headers to ECHO.
* **Reproducible Frontend Build:** TypeScript is locked through `package-lock.json` and CI uses `npm ci`. The Docker base is pinned to a specific PHP patch release and image digest.
* **Container Hardening:** The Compose service uses a read-only root filesystem, dedicated writable SQLite volume, temporary filesystems for runtime state, `no-new-privileges`, and conservative process/memory/CPU limits.

## 🛠️ Technology Stack
The browser client is written in **TypeScript** and relies on the native **Web Crypto API** for AES-256-GCM, HKDF-SHA-256, secure randomness, and SHA-256. The backend uses **PHP 8.2+** with **PDO SQLite**. **Apache**, **Docker**, and **Docker Compose** provide the reference deployment. A small QR encoder is vendored solely for local browser-side QR rendering; no third-party JavaScript is fetched at runtime. Playwright is used only by the test suite, never by the application.

## 📂 Repository Structure
* `/backend` - PHP API, configuration, HTTP abstractions, repository, security controls, observability, maintenance scripts, and backend tests.
* `/backend/public` - Production web root containing the front controller and built frontend assets.
* `/database` - SQLite schema for encrypted echoes and rate-limit buckets.
* `/frontend` - TypeScript browser source, cryptographic tests, build scripts, static assets, and vendored QR source.
* `/tests/e2e` - Cross-browser Playwright E2E and accessibility smoke tests.
* `/deploy/caddy` - Minimal HTTPS reverse-proxy example.
* `SECURITY.md` - Threat model, burn semantics, proxy trust, logging, backup policy, and limitations.
* `THIRD_PARTY_NOTICES.md` - Attribution for the vendored QR encoder.
* `Dockerfile` & `docker-compose.yml` - Pinned and hardened reference container deployment.
* `Makefile` - Convenience commands for build, validation, race tests, cleanup, E2E, and local serving.

## ⚙️ Installation & Usage
ECHO is intentionally a prototype: it is suitable for local/self-hosted experimentation and small trusted deployments, but it has not undergone an independent security audit.

1. **Clone the repository** and open a terminal in the `ECHO` directory.
2. **For local Docker use**, start the application:
   ```bash
   docker compose up --build
   ```
3. **Open ECHO** at `http://localhost:8080`.
4. **Create an Echo:** Enter a message, choose its lifetime and one-time reveal policy, then generate the encrypted share link.
5. **Share the Complete Link:** The secret is after the `#`; omitting that fragment makes decryption impossible.
6. **Reveal Explicitly:** The recipient selects **Reveal echo**, retrieves authorized ciphertext, and decrypts locally in the browser.

For local development without Docker, install **PHP 8.2+** with `pdo_sqlite`, **Node.js 22+**, and npm:

```bash
cd frontend
npm ci
npm run build
cd ..
php -S 127.0.0.1:8080 -t backend/public backend/public/index.php
```

Run the normal validation suite with:

```bash
make test
```

Run cross-browser E2E tests after installing Python Playwright and its browsers:

```bash
python3 tests/e2e/test_echo.py
```

Remove expired rows independently of normal traffic with:

```bash
php backend/bin/cleanup.php
```

Before exposing ECHO through HTTPS, generate a unique rate-limit secret and configure the reverse proxy explicitly:

```bash
export ECHO_RATE_LIMIT_SECRET="$(openssl rand -hex 32)"
export ECHO_TRUSTED_PROXIES="127.0.0.1"
export ECHO_ENABLE_HSTS=true
```

See [`deploy/caddy/Caddyfile.example`](deploy/caddy/Caddyfile.example) for a minimal Caddy reverse-proxy example and review [`SECURITY.md`](SECURITY.md) before Internet exposure.
