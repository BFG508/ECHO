#!/usr/bin/env python3
"""Cross-browser Playwright smoke tests for the ECHO prototype.

Requires PHP with pdo_sqlite plus the Python Playwright package and installed
Chromium, Firefox, and WebKit browsers. The application must already be built.
"""

from __future__ import annotations

import os
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
from pathlib import Path
from urllib.parse import urlsplit
from urllib.request import urlopen

from playwright.sync_api import (
    BrowserType,
    Page,
    TimeoutError as PlaywrightTimeoutError,
    sync_playwright,
)

ROOT = Path(__file__).resolve().parents[2]
HOST = "127.0.0.1"
PORT = 18087
BASE_URL = f"http://{HOST}:{PORT}"


def php_has_sqlite() -> bool:
    result = subprocess.run(
        ["php", "-r", "echo in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'yes' : 'no';"],
        check=False,
        capture_output=True,
        text=True,
    )
    return result.stdout.strip() == "yes"


def wait_for_server(timeout: float = 10.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with urlopen(f"{BASE_URL}/api/health", timeout=0.5) as response:
                if response.status == 200:
                    return
        except Exception:
            time.sleep(0.1)
    raise RuntimeError("ECHO test server did not become ready")


def goto_with_retry(page: Page, url: str) -> None:
    """Navigate with one retry for the Playwright/Firefox 1.62 goto flake."""
    try:
        page.goto(url, wait_until="load", timeout=10_000)
    except PlaywrightTimeoutError:
        page.goto(url, wait_until="load", timeout=10_000)


def create_echo(page: Page, message: str, burn: bool = True) -> str:
    goto_with_retry(page, BASE_URL)
    page.get_by_label("Message").fill(message)
    checkbox = page.get_by_role("checkbox", name="Burn after first reveal")
    if burn:
        checkbox.check()
    else:
        checkbox.uncheck()
    page.get_by_role("button", name="Create encrypted link").click()
    page.get_by_role("heading", name="Your echo is ready").wait_for()
    return page.get_by_role("textbox", name="Encrypted echo link", exact=True).input_value()


def reveal(page: Page, link: str) -> None:
    """Open an ECHO share link without a flaky Firefox full navigation.

    ECHO keeps the route and master secret in the URL fragment. Fragments are
    client-side only and are never sent to the server, so loading the origin
    first and then assigning location.hash exercises the same application
    routing while avoiding Playwright/Firefox navigation bookkeeping flakes.
    """
    parsed = urlsplit(link)
    base = urlsplit(BASE_URL)

    if parsed.scheme != base.scheme or parsed.netloc != base.netloc:
        raise AssertionError("Unexpected ECHO share-link origin")
    if parsed.path not in ("", "/") or not parsed.fragment.startswith("/open/"):
        raise AssertionError("Unexpected ECHO share-link format")

    goto_with_retry(page, BASE_URL)
    page.evaluate(
        "(fragment) => { window.location.hash = fragment; }",
        parsed.fragment,
    )
    page.get_by_role("button", name="Reveal echo").wait_for(state="visible")
    page.get_by_role("button", name="Reveal echo").click()


class EchoE2E(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if not php_has_sqlite():
            raise unittest.SkipTest("PHP PDO SQLite is required for E2E tests")
        if shutil.which("php") is None:
            raise unittest.SkipTest("PHP is required for E2E tests")

        cls.tmp = tempfile.TemporaryDirectory(prefix="echo-e2e-")
        db_path = Path(cls.tmp.name) / "echo.sqlite"
        env = os.environ.copy()
        env.update(
            {
                "ECHO_APP_ENV": "test",
                "ECHO_DSN": f"sqlite:{db_path}",
                "ECHO_RATE_LIMIT_SECRET": "e2e-rate-limit-secret-at-least-32-bytes",
                "ECHO_CREATE_LIMIT": "1000",
                "ECHO_REVEAL_LIMIT": "1000",
                "ECHO_LOG_LEVEL": "off",
            }
        )
        cls.server = subprocess.Popen(
            [
                "php",
                "-S",
                f"{HOST}:{PORT}",
                "-t",
                str(ROOT / "backend" / "public"),
                str(ROOT / "backend" / "public" / "index.php"),
            ],
            cwd=ROOT,
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        wait_for_server()
        cls.playwright = sync_playwright().start()

    @classmethod
    def tearDownClass(cls) -> None:
        if hasattr(cls, "playwright"):
            cls.playwright.stop()
        if hasattr(cls, "server"):
            cls.server.terminate()
            try:
                cls.server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                cls.server.kill()
        if hasattr(cls, "tmp"):
            cls.tmp.cleanup()

    def browser_types(self) -> list[tuple[str, BrowserType]]:
        return [
            ("chromium", self.playwright.chromium),
            ("firefox", self.playwright.firefox),
            ("webkit", self.playwright.webkit),
        ]

    def test_cross_browser_one_time_flow(self) -> None:
        for name, browser_type in self.browser_types():
            with self.subTest(browser=name):
                browser = browser_type.launch()
                try:
                    context = browser.new_context()
                    sender = context.new_page()
                    message = f"ECHO cross-browser secret — {name} 👋"
                    link = create_echo(sender, message, burn=True)

                    sender.get_by_text("256-bit local secret").wait_for()
                    sender.get_by_text("One-time reveal").wait_for()
                    sender.get_by_text("Show QR code").click()
                    qr = sender.locator("canvas.qr")
                    self.assertGreater(qr.evaluate("el => el.width"), 100)
                    self.assertGreater(qr.evaluate("el => el.height"), 100)

                    recipient = context.new_page()
                    reveal(recipient, link)
                    recipient.get_by_role("heading", name="The echo says").wait_for()
                    self.assertEqual(recipient.locator("pre.message").text_content(), message)
                    recipient.get_by_text("Retrieved and burned").wait_for()

                    second = context.new_page()
                    reveal(second, link)
                    second.get_by_text("does not exist, has expired, or has already been opened").wait_for()
                    context.close()
                finally:
                    browser.close()

    def test_reusable_echo_can_be_revealed_twice(self) -> None:
        browser = self.playwright.chromium.launch()
        try:
            context = browser.new_context()
            sender = context.new_page()
            link = create_echo(sender, "Reusable prototype echo", burn=False)
            for _ in range(2):
                recipient = context.new_page()
                reveal(recipient, link)
                recipient.get_by_role("heading", name="The echo says").wait_for()
                self.assertEqual(recipient.locator("pre.message").text_content(), "Reusable prototype echo")
                recipient.close()
            context.close()
        finally:
            browser.close()

    def test_keyboard_and_accessibility_smoke(self) -> None:
        browser = self.playwright.chromium.launch()
        try:
            page = browser.new_page()
            goto_with_retry(page, BASE_URL)
            page.get_by_role("heading", name="Send an echo").wait_for()
            active_tag = page.evaluate("document.activeElement?.id")
            self.assertEqual(active_tag, "app")
            self.assertEqual(page.get_by_label("Message").count(), 1)
            self.assertEqual(page.get_by_label("Expires after").count(), 1)
            self.assertEqual(page.get_by_role("button", name="Create encrypted link").count(), 1)
            unnamed_buttons = page.locator("button").evaluate_all(
                "els => els.filter(el => !(el.innerText || el.getAttribute('aria-label'))).length"
            )
            self.assertEqual(unnamed_buttons, 0)
            page.keyboard.press("Tab")
            self.assertTrue(page.evaluate("document.activeElement !== document.body"))
        finally:
            browser.close()

    def test_malformed_share_link_is_safe(self) -> None:
        browser = self.playwright.chromium.launch()
        try:
            page = browser.new_page()
            goto_with_retry(page, f"{BASE_URL}/#/open/not-valid")
            page.get_by_role("heading", name="This echo link is malformed").wait_for()
            self.assertEqual(page.locator("pre.message").count(), 0)
        finally:
            browser.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
