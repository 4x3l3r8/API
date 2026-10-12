#!/bin/sh
# One-shot job: schema + seed as the migration OWNER role (the app role cannot do DDL).
# The web/worker containers run init.sh with DISABLE_DB_MIGRATE/DISABLE_DB_SEED=true.
set -eu
cd /var/www/html

mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/app storage/logs
printf '%s' "$JWT_PUBLIC_KEY_BASE64"  | base64 -d > storage/oauth-public.key
printf '%s' "$JWT_PRIVATE_KEY_BASE64" | base64 -d > storage/oauth-private.key

php artisan migrate --force --no-interaction
php artisan db:seed --class=PermissionsSeeder --force --no-interaction

# init.sh only creates the Passport personal-access client when it generates the keys itself;
# we supply the keys, so make sure exactly one client exists (idempotent).
count=$(php -r '$p = new PDO("pgsql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $p->query("select count(*) from oauth_personal_access_clients")->fetchColumn();')
if [ "$count" = "0" ]; then
  php artisan passport:client --personal --no-interaction
fi
echo "migrate job done"
