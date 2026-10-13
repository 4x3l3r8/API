#!/bin/sh
# One-shot job: ensure baseline data for every tenant in the replica snapshot, as the RLS-bound APP role.
# Idempotent. Extra arguments are passed to the command (e.g. a single tenant id).
set -eu
cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/app storage/logs
printf '%s' "$JWT_PUBLIC_KEY_BASE64"  | base64 -d > storage/oauth-public.key
printf '%s' "$JWT_PRIVATE_KEY_BASE64" | base64 -d > storage/oauth-private.key

if [ "$#" -eq 0 ]; then
  set -- --all
fi
php artisan luminate:tenant:provision "$@"
