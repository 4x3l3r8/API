#!/bin/sh
# Runs once on a fresh data dir, after 01-roles.sh. Installs the tenancy functions AS THE OWNER ROLE (so the owner can
# CREATE OR REPLACE them from the Laravel migration later) and creates the DDL event trigger, which needs superuser.
set -eu

psql -v ON_ERROR_STOP=1 -v owner="$DB_OWNER_USER" --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
set role :"owner";
\i /luminate-sql/001_functions.sql
reset role;

drop event trigger if exists luminate_tenancy_ddl;
create event trigger luminate_tenancy_ddl on ddl_command_end
    when tag in ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO', 'ALTER TABLE', 'CREATE INDEX')
    execute function public.luminate_on_ddl();
SQL
