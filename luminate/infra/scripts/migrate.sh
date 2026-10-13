#!/bin/sh
# One-shot job: schema as the migration OWNER role (the app role cannot do DDL).
# Seeding is NOT done here: the owner bypasses row-level security and has no tenant, so anything it inserted would be an
# invisible orphan. Per-tenant baseline data (roles, permissions, first admin, Passport client) is created by the
# `provision` job, which runs as the RLS-bound app role (luminate:tenant:provision).
set -eu
cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/app storage/logs
printf '%s' "$JWT_PUBLIC_KEY_BASE64"  | base64 -d > storage/oauth-public.key
printf '%s' "$JWT_PRIVATE_KEY_BASE64" | base64 -d > storage/oauth-private.key

php artisan migrate --force --no-interaction
echo "migrate job done"
