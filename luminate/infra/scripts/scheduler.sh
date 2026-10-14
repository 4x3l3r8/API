#!/bin/sh
# Per-tenant scheduler: replaces Wellms' stock tenant-less `schedule:run` loop (which is disabled in the worker).
# Runs as the RLS-bound APP role. Tenants come from the local replica snapshot, not from a static list.
set -eu
cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/app storage/logs
printf '%s' "$JWT_PUBLIC_KEY_BASE64"  | base64 -d > storage/oauth-public.key
printf '%s' "$JWT_PRIVATE_KEY_BASE64" | base64 -d > storage/oauth-private.key

exec php artisan luminate:schedule:work "$@"
