# E.C.H.O. 🔐
**E**phemeral **C**ryptographic **H**andoff **O**nline

A lightweight, self-hostable web application for securely sharing end-to-end encrypted, expiring messages. ECHO performs all encryption and decryption directly in the browser, allowing the backend to store only ciphertext while the master secret remains exclusively in the shared link fragment and is never transmitted to the server.

## 🚀 Core Features
### 1. End-to-End Client-Side Encryption
* **AES-256-GCM Encryption:** Messages are encrypted locally in the browser using the native Web Crypto API, providing both confidentiality and authenticated integrity without relying on custom cryptographic primitives.
* **HKDF-SHA-256 Key Separation:** Each echo starts from a random 256-bit master secret. Independent encryption and access material are derived with HKDF-SHA-256 using the echo identifier as salt and separate domain labels.
* **Authenticated Context Binding:** The unique echo ID is included as Additional Authenticated Data (AAD), preventing encrypted payloads from being silently substituted between different echoes.

### 2. Ephemeral & One-Time Message Delivery
* **Burn After Reading:** One-time echoes are deleted atomically from the live database as part of the successful reveal transaction, preventing a second retrieval of the same message.
* **Configurable Expiration:** Messages can automatically expire after 5 minutes, 1 hour, 1 day, or 7 days, independently of whether they have been opened.
* **Preview-Safe Reveal Flow:** Opening a shared link does not automatically consume the message. The recipient must explicitly select **Reveal echo**, preventing ordinary messaging-app link previews and crawlers from burning one-time content.

### 3. Privacy-Oriented Security Architecture
* **Server-Blind Secret Handling:** Share links keep the master secret after the URL `#` fragment, which browsers do not include in normal HTTP requests. The server therefore receives the encrypted payload and opaque identifier, but not the secret required to decrypt it.
* **Protected Reveal Authorization:** Knowing an echo ID alone is insufficient to retrieve or destroy a message. A separate HKDF-derived access proof is verified by the backend before ciphertext is returned.
* **Minimal Tracking Surface:** ECHO uses no accounts, cookies, local storage, analytics, trackers, external fonts, or third-party runtime scripts. The backend additionally applies strict input validation, prepared SQL statements, request-size limits, exact-origin checks, security headers, and hashed-IP rate limiting.

### 4. Reliable Self-Hosted Deployment
* **Atomic SQLite Persistence:** One-time reveals use explicit write transactions to preserve burn-after-reading semantics under concurrent access, with SQLite WAL mode and `secure_delete` enabled as defense in depth.
* **Automated Validation:** The repository includes strict TypeScript checking, frontend cryptographic tests, backend tests, PHP syntax validation, and a GitHub Actions CI workflow.
* **Containerized Runtime:** Docker and Docker Compose definitions provide a reproducible deployment path with persistent encrypted-message storage and an application health check.

## 🛠️ Technology Stack
ECHO uses a deliberately small and dependency-light stack. The frontend is written in **TypeScript** and relies on the browser-native **Web Crypto API** for AES-256-GCM, HKDF-SHA-256, secure random generation, and cryptographic hashing. The backend is implemented in **PHP 8.2+** and exposes a compact JSON API responsible for validation, authorization, rate limiting, expiry enforcement, and ciphertext persistence. **SQLite** provides transactional storage, while **Apache**, **Docker**, and **Docker Compose** support straightforward self-hosted deployment. No frontend framework or third-party runtime JavaScript is required.

## 📂 Repository Structure
* `/backend` - PHP application containing the API entry point, configuration, controllers, HTTP abstractions, persistence layer, security controls, initialization utilities, and backend tests.
* `/backend/public` - Production web root containing the PHP front controller together with the compiled frontend assets served to the browser.
* `/database` - SQLite schema defining encrypted echoes, expiry metadata, and rate-limiting storage.
* `/frontend` - TypeScript source code for the browser client, including API communication, cryptographic operations, UI rendering, types, build synchronization, and cryptographic tests.
* `Dockerfile` & `docker-compose.yml` - Containerized deployment configuration with persistent SQLite storage.
* `SECURITY.md` - Threat model, security assumptions, operational requirements, and known limitations of the web-based end-to-end encryption model.
* `Makefile` - Convenience commands for building, testing, validating, and running the project.

## ⚙️ Installation & Usage
ECHO can be run directly with PHP for development or deployed through Docker for the simplest complete setup.

1. **Clone or download the repository** and open a terminal in the `ECHO` directory.
2. **Start ECHO with Docker:**
   ```bash
   docker compose up --build
   ```
3. **Open the application** at `http://localhost:8080`.
4. **Create an Echo:** Enter the message, select its expiration policy and whether it should be destroyed after its first successful reveal, then generate the encrypted share link.
5. **Share the Link:** Send the complete generated URL to the intended recipient. The master secret remains inside the URL fragment and is required for local decryption.
6. **Reveal the Message:** The recipient opens the link and explicitly selects **Reveal echo**. The encrypted payload is authorized and retrieved from the backend, then decrypted locally in the browser.

For local development without Docker, install **PHP 8.2+** with `pdo_sqlite`, **Node.js 22+**, and npm. Build the frontend with `npm install` followed by `npm run build` inside `/frontend`, then start the application with:

```bash
php -S 127.0.0.1:8080 -t backend/public backend/public/index.php
```

Before exposing ECHO to the Internet, deploy it exclusively over **HTTPS** and review [`SECURITY.md`](SECURITY.md). End-to-end encryption protects message content, but the security of a browser-based cryptographic application still depends on the integrity of the JavaScript delivered by the hosting environment.
