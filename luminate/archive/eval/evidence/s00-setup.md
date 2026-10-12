# S0 Setup log (Verified)

Run: `docker compose -f api/docker-compose.yml -f docker-compose.override.yml --project-directory api -p wellms up -d --build`
Base URL: `http://127.0.0.1:8080` with header `Host: api.localhost` (Caddy routes by hostname; host 80/443 held by Windows System).

## Deviations from the repo's stock setup
1. Host 80/443 busy -> Caddy remapped to 8080/8443 (override file).
2. `bitnami/minio:latest` no longer exists on Docker Hub -> `bitnamilegacy/minio:latest`.
3. Windows git checkout gave CRLF -> `/bin/sh: init.sh: not found`. Fixed by `core.autocrlf=false` + re-checkout. (Windows-only gotcha.)
4. Repo bind-mounts `./` over `/var/www/html`, hiding image `vendor/`; override drops the mount.
5. First boot builds `composer install` in-image: ~10 min.

## Facts
- Laravel Framework 9.52.21, PHP 8.3.23, Passport (JWT RS256 tokens), 264 `escolalms/*` packages in composer.lock.
- Services (12 containers): caddy, api (php-fpm+horizon+scheduler via supervisord), postgres 12, redis, minio, soketi (websockets), mailhog, mjml, reportbro, adminer, admin UI, demo front.
- Admin auto-created at boot: `admin@escolalms.com` / `secret` (docs/multidomain.md says `escolasoft.com`; stale). `php artisan escolalms:admin` exists but is interactive.
- `GET /api/name` -> `Application Name: Wellms`
- Swagger UI: `GET /api/documentation` 200; spec at `/docs?api-docs.json`: OpenAPI 3.0.0, title "EscolaLMS", version "0.0.1", 338 paths / 439 operations (saved: `evidence/openapi.json`).
- `POST /api/auth/login {"email","password"}` -> `{success,message,data:{token,expires_at}}`; token `expires_at` ~5 min after issue (to examine in S5).
- `GET /api/profile/me` with Bearer -> 200, roles ["admin"], full permission list.
- `GET /api/admin/courses?per_page=2` -> 200, empty list with pagination meta.
