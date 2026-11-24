.PHONY: lint lint-fix fix stan check test contracts sync-contracts release e2e

# PHP CodeSniffer — check WordPress coding standards
lint:
	vendor/bin/phpcs --standard=.phpcs.xml.dist

# PHP CodeSniffer — auto-fix violations
lint-fix:
	vendor/bin/phpcbf --standard=.phpcs.xml.dist

# PHP CS Fixer — auto-format code
fix:
	vendor/bin/php-cs-fixer fix

# PHPStan — static analysis (level 5)
stan:
	vendor/bin/phpstan analyse

# All static checks
check: lint stan

# Unit tests (Brain Monkey — no WordPress needed)
test:
	vendor/bin/phpunit

# Protocol-compat gate — contracts/ must equal server spec verbatim
# + MANIFEST checksums valid (SLICE-262-33). CI runs this.
contracts:
	bash bin/check-contracts.sh

# Re-sync contracts/ from the authoritative server spec + regen manifest
sync-contracts:
	bash bin/sync-contracts.sh

# release-manifest.json per D-262-Q12 (version, protocol, ranges,
# changelog, sha256 of shipped files, security contact)
release:
	php bin/build-release.php

# e2e harness (fake backend + wp-stubs)
e2e:
	bash tests/e2e/run-e2e.sh
