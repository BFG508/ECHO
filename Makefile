.PHONY: build test test-unit test-race test-e2e lint serve clean cleanup-expired

build:
	cd frontend && npm ci --no-audit --no-fund && npm run build

lint:
	@find backend -name '*.php' -print0 | xargs -0 -n1 php -l
	cd frontend && npm run typecheck

test-unit:
	$(MAKE) lint
	php backend/tests/run.php
	cd frontend && npm test

test-race:
	php backend/tests/concurrency.php

test-e2e:
	python3 tests/e2e/test_echo.py

test: test-unit test-race

cleanup-expired:
	php backend/bin/cleanup.php

serve:
	php -S 127.0.0.1:8080 -t backend/public backend/public/index.php

clean:
	rm -rf frontend/node_modules frontend/public/assets backend/public/assets
