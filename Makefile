.PHONY: build test lint serve clean

build:
	cd frontend && npm install && npm run build

lint:
	@find backend -name '*.php' -print0 | xargs -0 -n1 php -l
	cd frontend && npm run typecheck

test:
	cd frontend && npm install --no-audit --no-fund
	$(MAKE) lint
	php backend/tests/run.php
	cd frontend && npm test

serve:
	php -S 127.0.0.1:8080 -t backend/public backend/public/index.php

clean:
	rm -rf frontend/node_modules frontend/public/assets backend/public/assets
