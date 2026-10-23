# Thin wrapper over the composer scripts, so `make ci` works the same locally and in docs.
.PHONY: install test stan style fix ci audit hooks

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

ci: style stan test

hooks:
	scripts/install-hooks.sh
