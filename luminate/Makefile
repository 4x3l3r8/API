# Release gate and suites. Run from luminate/ with the dev stack up (infra/README.md). Needs `python` (3.10+) and docker on PATH.
# The LAN IP changes with the network; point the HTTP suites at localhost with LUMINATE_TEST_HOST=127.0.0.1.
PY ?= python -I
SMOKE := context_token_smoke isolation_smoke_4b queue_failclosed token_binding config_isolation storage_isolation scheduler_per_tenant ops_guards prod_posture registry_sync webhooks_e2e quiz_timeout_probe

.PHONY: gate gate-both suites unit

# The generated cross-tenant isolation suite over every operation (A attacks B). Exit code 1 on any violation.
gate:
	cd infra && $(PY) ../tests/gate/isolation_gate.py

# Both directions; the second reuses the data the first seeded.
gate-both:
	cd infra && $(PY) ../tests/gate/isolation_gate.py && $(PY) ../tests/gate/isolation_gate.py --attacker t-beta --victim t-alpha --no-seed

suites:
	cd infra && $(PY) ../tests/sql/rls_checks.py && for t in $(SMOKE); do $(PY) ../tests/smoke/$$t.py || exit 1; done

unit:
	cd addons/luminate-tenancy && composer install --no-interaction && vendor/bin/phpunit
