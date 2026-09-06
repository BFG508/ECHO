"""Explicit XSS regression checks for E.C.H.O.

These tests guard the security boundary where decrypted, attacker-controlled text is
rendered in the recipient's browser. They intentionally inspect first-party source
instead of vendor code. The goal is to make unsafe HTML execution sinks a CI failure.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FRONTEND_SRC = ROOT / "frontend" / "src"
DOM_HELPER = FRONTEND_SRC / "ui" / "dom.ts"

XSS_PAYLOADS = (
    '<script>globalThis.__echoXss = true</script>',
    '<img src=x onerror="globalThis.__echoXss = true">',
    '<svg onload="globalThis.__echoXss = true"></svg>',
    '"><details open ontoggle="globalThis.__echoXss = true">',
)

# Calls that turn strings into executable HTML/JavaScript rather than plain text.
UNSAFE_CALL_PATTERNS = (
    ("insertAdjacentHTML", re.compile(r"\.insertAdjacentHTML\s*\(")),
    ("document.write", re.compile(r"\bdocument\.write(?:ln)?\s*\(")),
    ("eval", re.compile(r"\beval\s*\(")),
    ("Function constructor", re.compile(r"\bnew\s+Function\s*\(")),
)

UNSAFE_ASSIGNMENT_PATTERN = re.compile(
    r"\.(?P<sink>innerHTML|outerHTML|srcdoc)\s*=\s*(?P<rhs>.+?);?\s*$"
)


def _code_lines(source: str):
    """Yield likely code lines while ignoring ordinary comment-only lines."""

    in_block_comment = False
    for number, raw_line in enumerate(source.splitlines(), start=1):
        stripped = raw_line.strip()

        if in_block_comment:
            if "*/" in stripped:
                in_block_comment = False
            continue

        if stripped.startswith("/*"):
            if "*/" not in stripped[2:]:
                in_block_comment = True
            continue

        if not stripped or stripped.startswith("//") or stripped.startswith("*"):
            continue

        yield number, stripped


def _is_safe_empty_inner_html(sink: str, rhs: str) -> bool:
    """Allow only the common non-executing clear operation: node.innerHTML = ''."""

    if sink != "innerHTML":
        return False

    normalized = rhs.rstrip(";").strip()
    return normalized in {"''", '""', "``"}


class XssRedTeamRegression(unittest.TestCase):
    """Fail CI if decrypted content can drift toward executable HTML sinks."""

    def test_decrypted_text_contract_uses_text_content(self) -> None:
        self.assertTrue(DOM_HELPER.is_file(), f"Missing DOM helper: {DOM_HELPER}")
        source = DOM_HELPER.read_text(encoding="utf-8")

        self.assertRegex(
            source,
            re.compile(r"\.textContent\s*=\s*text\s*;?"),
            "The DOM text helper must render attacker-controlled text with textContent.",
        )
        self.assertNotRegex(
            source,
            re.compile(r"\.(?:innerHTML|outerHTML|srcdoc)\s*=\s*text\b"),
            "The DOM text helper must never route decrypted text into an HTML sink.",
        )

    def test_first_party_typescript_has_no_executable_html_sinks(self) -> None:
        self.assertTrue(FRONTEND_SRC.is_dir(), f"Missing frontend source: {FRONTEND_SRC}")

        violations: list[str] = []
        scanned = 0

        for path in sorted(FRONTEND_SRC.rglob("*.ts")):
            scanned += 1
            source = path.read_text(encoding="utf-8")
            relative = path.relative_to(ROOT)

            for line_number, line in _code_lines(source):
                for label, pattern in UNSAFE_CALL_PATTERNS:
                    if pattern.search(line):
                        violations.append(f"{relative}:{line_number}: unsafe {label}: {line}")

                assignment = UNSAFE_ASSIGNMENT_PATTERN.search(line)
                if assignment and not _is_safe_empty_inner_html(
                    assignment.group("sink"), assignment.group("rhs")
                ):
                    violations.append(
                        f"{relative}:{line_number}: unsafe {assignment.group('sink')} assignment: {line}"
                    )

        self.assertGreater(scanned, 0, "No first-party TypeScript files were scanned.")
        self.assertFalse(
            violations,
            "Potential XSS execution sinks found in first-party TypeScript:\n"
            + "\n".join(violations),
        )

    def test_red_team_payload_corpus_is_html_bearing(self) -> None:
        """Keep an explicit malicious corpus visible in the test suite."""

        self.assertGreaterEqual(len(XSS_PAYLOADS), 4)
        for payload in XSS_PAYLOADS:
            with self.subTest(payload=payload):
                self.assertIn("<", payload)
                self.assertIn(">", payload)


if __name__ == "__main__":
    unittest.main()
