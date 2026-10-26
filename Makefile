# Thin wrapper over the composer scripts, so `make ci` works the same locally and in docs.
.PHONY: install test test-serial coverage badges stan style fix ci audit hooks release-guards-test

install:
	composer install --no-interaction --prefer-dist

test:
	composer test

stan:
	composer stan

style:
	composer style

fix:
	vendor/bin/pint

audit:
	composer audit --locked

test-serial:
	composer test:serial

# Needs pcov (preferred) or xdebug; writes build/coverage/{cobertura,clover}.xml, html/, summary.txt.
coverage:
	composer coverage

badges:
	php scripts/badges.php

# Tests scripts/check-release.sh (the release workflow's guards) against a scratch git repository.
release-guards-test:
	tests/release/check-release-test.sh

ci: style stan test release-guards-test

hooks:
	scripts/install-hooks.sh
