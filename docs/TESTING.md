# ECHO prototype validation

## Automated checks

```bash
make test
```

Runs PHP syntax validation, strict TypeScript checking, backend/fuzz tests, frontend cryptographic/property tests, and the 50-worker one-time reveal race test.

Cross-browser E2E requires PHP `pdo_sqlite`, Python Playwright and installed browsers:

```bash
python3 tests/e2e/test_echo.py
```

CI installs Playwright and runs the flow against Chromium, Firefox, and WebKit.

## Manual accessibility pass

Before calling a prototype snapshot complete, check at least:

1. Keyboard-only navigation through create, share, reveal, copy and QR disclosure flows.
2. Visible focus on every interactive control.
3. Screen-reader labels for message, expiry, burn policy, share URL, reveal and QR canvas.
4. Status/error announcements after create/copy/reveal failures.
5. Light and dark mode contrast.
6. 200% browser zoom and a narrow mobile viewport.
7. One manual pass with NVDA/Firefox or VoiceOver/Safari when those platforms are available.

The automated smoke test intentionally does not pretend to replace real assistive-technology testing.
