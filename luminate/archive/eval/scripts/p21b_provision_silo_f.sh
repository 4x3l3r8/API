#!/bin/sh
# Hot-provision a NEW siloed tenant (no container restart): DB+role, bucket, domain, env, migrate, seed, keys. Times each step.
set -e
D=tenant-f.localhost; K=TENANT_F; N=tenant_f
t0=$(date +%s)
step() { now=$(date +%s); echo "  +$((now-t0))s  $1"; }
docker exec wellms-postgres-1 psql -U default -d default -qc "CREATE USER $N WITH PASSWORD '${N}_pw';" -c "CREATE DATABASE $N OWNER $N;" >/dev/null; step "postgres role + database"
docker exec wellms-minio-1 mc mb --ignore-existing local/tenant-f >/dev/null 2>&1; step "minio bucket"
docker exec wellms-api-1 sh -c "cd /var/www/html && php artisan domain:add $D >/dev/null 2>&1 && php artisan domain:update_env $D --domain_values='{\"APP_NAME\":\"Tenant_Foxtrot\",\"APP_URL\":\"http://$D\",\"DB_DATABASE\":\"$N\",\"DB_USERNAME\":\"$N\",\"DB_PASSWORD\":\"${N}_pw\",\"AWS_BUCKET\":\"tenant-f\",\"AWS_URL\":\"http://storage.localhost/tenant-f\",\"INITIAL_USER_EMAIL\":\"admin@tenant-f.test\",\"INITIAL_USER_PASSWORD\":\"foxtrot-secret\",\"REDIS_DB\":\"10\"}' >/dev/null 2>&1"; step "domain:add + env (hot, no restart)"
docker exec wellms-api-1 sh -c "cd /var/www/html && php artisan migrate --force --domain=$D >/dev/null 2>&1"; step "migrate 151 tables"
docker exec wellms-api-1 sh -c "cd /var/www/html && php artisan key:generate --force --no-interaction --domain=$D >/dev/null 2>&1; php artisan passport:keys --force --no-interaction --domain=$D >/dev/null 2>&1; php artisan passport:client --personal --no-interaction --domain=$D >/dev/null 2>&1"; step "app key + passport keys + client"
docker exec wellms-api-1 sh -c "cd /var/www/html && php artisan db:seed --class=PermissionsSeeder --force --no-interaction --domain=$D >/dev/null 2>&1; chown -R www-data:www-data storage"; step "seed permissions + initial admin"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $D" http://127.0.0.1:8080/api/name); step "first request /api/name -> $code"
curl -s -H "Host: $D" -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"admin@tenant-f.test","password":"foxtrot-secret"}' http://127.0.0.1:8080/api/auth/login | head -c 60; echo
