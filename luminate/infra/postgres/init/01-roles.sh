#!/bin/sh
# Runs once, on first start of an empty data dir, as the bootstrap superuser.
# Three non-superuser roles, none can bypass RLS:
#   wellms_owner    - owns the schema; used ONLY by the one-shot migrate job
#   wellms_app      - used by the web and worker; DML only, never owns a table
#   wellms_registry - the ONLY role that may write the tenant-registry replica (contract A.2.3). It can log in and nothing else: the
#                     grants on the two registry tables are made by the luminate_registry_lockdown migration. The app role is read-only there.
set -eu

psql -v ON_ERROR_STOP=1 -v owner="$DB_OWNER_USER" -v owner_pw="$DB_OWNER_PASSWORD" \
     -v app="$DB_APP_USER" -v app_pw="$DB_APP_PASSWORD" -v db="$POSTGRES_DB" \
     -v reg="$DB_REGISTRY_USER" -v reg_pw="$DB_REGISTRY_PASSWORD" \
     --username "$POSTGRES_USER" --dbname postgres <<'SQL'
CREATE ROLE :"owner" LOGIN PASSWORD :'owner_pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
CREATE ROLE :"app"   LOGIN PASSWORD :'app_pw'   NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
CREATE ROLE :"reg"   LOGIN PASSWORD :'reg_pw'   NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
ALTER DATABASE :"db" OWNER TO :"owner";
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO :"app";
GRANT CONNECT ON DATABASE :"db" TO :"reg";
SQL

psql -v ON_ERROR_STOP=1 -v owner="$DB_OWNER_USER" -v app="$DB_APP_USER" -v reg="$DB_REGISTRY_USER" \
     --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
ALTER SCHEMA public OWNER TO :"owner";
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO :"app";
GRANT USAGE ON SCHEMA public TO :"reg";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO :"app";
SQL
