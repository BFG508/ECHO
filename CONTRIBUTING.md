# Contributing to ECHO

Thank you for contributing to ECHO. Security properties are part of the product contract rather than optional implementation details. Changes that weaken those properties must not be merged merely to make a feature or test pass.

## Development rules

Source code, identifiers, database names, inline comments, and docstrings must be written in English. Keep public technical documentation consistent with the terminology already used by the repository.

All first-party PHP code under `backend/` must comply with PHP-FIG PER Coding Style. CI enforces this rule with the repository's `.php-cs-fixer.dist.php` configuration.

Run the same check locally with:

```bash
composer global require --no-interaction --no-progress friendsofphp/php-cs-fixer:3.95.24
"$(composer global config bin-dir --absolute)/php-cs-fixer" fix \
  --dry-run \
  --diff \
  --verbose \
  --using-cache=no \
  --config=.php-cs-fixer.dist.php
```

Use the fixer without `--dry-run` only when you intentionally want it to rewrite files. Review the resulting diff before committing.

## Security invariants

Preserve the following invariants in every change:

- Plaintext encryption and decryption happen on the client side.
- The master secret must never be sent to the backend, persisted server-side, or written to logs.
- The backend must remain unable to recover message plaintext from stored data alone.
- Decrypted, user-controlled content must be rendered as text. Do not introduce `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `srcdoc`, `document.write`, `eval`, or equivalent executable sinks for message content.
- Data-bearing SQL must use PDO prepared statements and bound parameters. Never build SQL by concatenating or interpolating request-controlled values.
- Do not weaken access-proof validation, expiry/burn semantics, rate limiting, origin/proxy validation, security headers, or cryptographic authentication to make a feature pass.
- Do not put secrets, plaintext, access proofs, or sensitive request material in logs or error messages.

Constant SQL statements that contain no request-controlled data, such as transaction control or fixed SQLite PRAGMAs, do not require parameter binding.

## Testing expectations

Before opening a pull request, run the existing project test commands documented by the repository and the Red Team regression suite:

```bash
python3 -m unittest discover -s tests/red_team -p 'test_*.py' -v
```

For security-sensitive code, add a regression test that fails before the fix and passes after it. Do not replace a meaningful test with a weaker assertion, skip, or broad exception.

## Pull requests

Keep pull requests focused. In the description, explain:

- what changed and why;
- which security boundaries are touched;
- which tests were added or updated;
- whether database, cryptographic, API, deployment, or browser behavior changes;
- any compatibility or migration implications.

A pull request is ready for review only when the existing CI and the `PER and Red Team` workflow are green.
