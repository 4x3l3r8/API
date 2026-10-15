#!/bin/sh
# One-shot job: seed the registry replica from the dev snapshot (insert-only; skipped when there is none), then ensure baseline data
# for every tenant in the replica, as the RLS-bound APP role.
# Idempotent. Extra arguments are passed to the command (e.g. a single tenant id).
set -eu
cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/app storage/logs
printf '%s' "$JWT_PUBLIC_KEY_BASE64"  | base64 -d > storage/oauth-public.key
printf '%s' "$JWT_PRIVATE_KEY_BASE64" | base64 -d > storage/oauth-private.key

if [ "$#" -eq 0 ]; then
  set -- --all
fi
# first boot only: tenants the registry has never heard of. Production has no snapshot file; Luminate pushes tenants instead.
php artisan luminate:registry:import
php artisan luminate:tenant:provision "$@"
