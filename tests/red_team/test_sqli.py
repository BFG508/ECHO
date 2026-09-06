"""Explicit SQL-injection regression checks for E.C.H.O.

The backend's data-bearing SQL must go through PDO prepared statements. Constant
transaction/PRAGMA statements may use exec(), but dynamic values must never be
concatenated or interpolated into SQL executed by PDO.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BACKEND_SRC = ROOT / "backend" / "src"

SQLI_PAYLOADS = (
    "' OR 1=1 --",
    "' UNION SELECT NULL --",
    "'; DROP TABLE echoes; --",
    '" OR "1"="1" --',
)

# Dynamic direct execution is unsafe because there is no parameter-binding boundary.
DYNAMIC_DIRECT_EXECUTION = re.compile(
    r"->\s*(?P<method>query|exec)\s*\(\s*(?P<arg>\$[A-Za-z_][A-Za-z0-9_]*|\"[^\"]*\$[A-Za-z_]|'[^']*'\s*\.|\"[^\"]*\"\s*\.)",
    re.MULTILINE,
)

# prepare() is only safe when values stay out of the SQL structure. Catch the common
# dangerous forms where a variable is interpolated/concatenated directly into the SQL.
DYNAMIC_PREPARE = re.compile(
    r"->\s*prepare\s*\(\s*(?:\"(?:[^\"\\]|\\.)*\$[A-Za-z_][A-Za-z0-9_]*|(?:'[^']*'|\"[^\"]*\")\s*\.\s*\$[A-Za-z_])",
    re.MULTILINE,
)


def _strip_php_comments(source: str) -> str:
    """Remove comments so security prose/examples do not trigger the scanner."""

    source = re.sub(r"/\*.*?\*/", "", source, flags=re.DOTALL)
    source = re.sub(r"(^|\s)//.*?$", r"\1", source, flags=re.MULTILINE)
    source = re.sub(r"(^|\s)#.*?$", r"\1", source, flags=re.MULTILINE)
    return source


def _is_trusted_schema_exec(relative: Path, source: str, match: re.Match[str]) -> bool:
    """Allow only ECHO's static local schema bootstrap through PDO::exec()."""

    if relative != Path("backend/src/Config/Database.php"):
        return False

    if match.group("method") != "exec" or match.group("arg") != "$schema":
        return False

    schema_path_is_static = re.search(
        r"\$schemaPath\s*=\s*dirname\(__DIR__,\s*3\)\s*\.\s*['\"]/database/init_schema\.sql['\"]\s*;",
        source,
    )
    schema_loaded_from_file = re.search(
        r"\$schema\s*=\s*@?file_get_contents\(\$schemaPath\)\s*;",
        source,
    )

    return bool(schema_path_is_static and schema_loaded_from_file)


class SqliRedTeamRegression(unittest.TestCase):
    """Guard PDO usage against accidental return to string-built SQL."""

    def test_backend_uses_prepared_statements(self) -> None:
        self.assertTrue(BACKEND_SRC.is_dir(), f"Missing backend source: {BACKEND_SRC}")
        source = "\n".join(
            path.read_text(encoding="utf-8")
            for path in sorted(BACKEND_SRC.rglob("*.php"))
        )
        self.assertIn(
            "->prepare(",
            source.replace(" ", ""),
            "Expected PDO prepared statements were not found in backend/src.",
        )

    def test_no_dynamic_sql_is_executed_directly(self) -> None:
        violations: list[str] = []
        scanned = 0

        for path in sorted(BACKEND_SRC.rglob("*.php")):
            scanned += 1
            source = _strip_php_comments(path.read_text(encoding="utf-8"))
            relative = path.relative_to(ROOT)

            for pattern, label in (
                (DYNAMIC_DIRECT_EXECUTION, "dynamic query()/exec()"),
                (DYNAMIC_PREPARE, "interpolated/concatenated prepare()"),
            ):
                for match in pattern.finditer(source):
                    if pattern is DYNAMIC_DIRECT_EXECUTION and _is_trusted_schema_exec(
                        relative, source, match
                    ):
                        continue

                    line_number = source.count("\n", 0, match.start()) + 1
                    excerpt = " ".join(match.group(0).split())
                    violations.append(f"{relative}:{line_number}: {label}: {excerpt}")

        self.assertGreater(scanned, 0, "No backend PHP files were scanned.")
        self.assertFalse(
            violations,
            "Potential SQL-injection construction paths found:\n" + "\n".join(violations),
        )

    def test_red_team_payload_corpus_contains_statement_breakers(self) -> None:
        """Keep representative SQLi payloads explicit and reviewable in the suite."""

        self.assertGreaterEqual(len(SQLI_PAYLOADS), 4)
        self.assertTrue(any("UNION" in payload for payload in SQLI_PAYLOADS))
        self.assertTrue(any("DROP TABLE" in payload for payload in SQLI_PAYLOADS))
        self.assertTrue(any("OR 1=1" in payload for payload in SQLI_PAYLOADS))


if __name__ == "__main__":
    unittest.main()
