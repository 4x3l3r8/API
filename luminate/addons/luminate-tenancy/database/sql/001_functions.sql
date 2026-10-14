-- luminate/tenancy: tenant-isolation primitives for the pooled model (contract v3 §H, "tenant_id on every tenant-owned table").
--
-- Idempotent. Run as the MIGRATION-OWNER role (never as the app role):
--   * on a fresh database: postgres/init/02-tenancy.sh runs it with SET ROLE <owner>, then creates the event trigger
--     (creating an event trigger needs superuser, so that one statement cannot live in a Laravel migration);
--   * on every `artisan migrate`: the add-on migration re-applies it (CREATE OR REPLACE) and enrols existing tables.
--
-- Model: every table in schema public is tenant-owned unless listed in luminate_tenancy_exempt. A tenant-owned table has
--   tenant_id varchar(100) DEFAULT luminate_tenant_id()  -- stamps every write path: Eloquent, DB::table, raw SQL, pivots
--   ROW LEVEL SECURITY ENABLED + policy tenant_isolation  -- USING and WITH CHECK tenant_id = luminate_tenant_id()
-- luminate_tenant_id() reads the transaction-local setting app.tenant_id (SET LOCAL semantics, PgBouncer-safe). Unset or
-- empty => NULL => the policy matches nothing (fails closed). RLS is ENABLEd, not FORCEd: the owner role (migrations only)
-- bypasses it; the app role, which owns nothing, never does.

create table if not exists public.luminate_tenancy_exempt (
    table_name text primary key,
    reason     text not null
);

create table if not exists public.luminate_tenancy_audit (
    id     bigserial primary key,
    at     timestamptz not null default now(),
    kind   text not null,
    object text not null,
    detail text
);

insert into public.luminate_tenancy_exempt (table_name, reason) values
    ('migrations',              'Laravel migration ledger'),
    ('failed_jobs',             'global queue failure log; payloads are only read from the CLI, never exposed by the API'),
    ('luminate_tenancy_exempt', 'tenancy metadata'),
    ('luminate_tenancy_audit',  'tenancy metadata')
on conflict (table_name) do nothing;

create or replace function public.luminate_tenant_id() returns text
language sql stable as
$$ select nullif(current_setting('app.tenant_id', true), '') $$;

create or replace function public.luminate_is_exempt(tbl text) returns boolean
language sql stable as
$$ select exists (select 1 from public.luminate_tenancy_exempt where table_name = tbl) $$;

-- Make one table tenant-owned: tenant_id column + default, index, RLS, policy.
create or replace function public.luminate_enroll_table(rel regclass) returns void
language plpgsql as
$$
declare
    t   text := (select relname from pg_class where oid = rel);
    idx text;
begin
    if public.luminate_is_exempt(t) then
        return;
    end if;
    execute format('alter table %s add column if not exists tenant_id varchar(100)', rel);
    execute format('alter table %s alter column tenant_id set default public.luminate_tenant_id()', rel);
    idx := left(t, 40) || '_tid_' || substr(md5(t), 1, 8);
    execute format('create index if not exists %I on %s (tenant_id)', idx, rel);
    execute format('alter table %s enable row level security', rel);
    execute format('drop policy if exists tenant_isolation on %s', rel);
    execute format(
        'create policy tenant_isolation on %s using (tenant_id = public.luminate_tenant_id()) with check (tenant_id = public.luminate_tenant_id())',
        rel);
end
$$;

-- Record (never auto-rewrite) unique indexes on tenant-owned tables that do not lead with tenant_id.
create or replace function public.luminate_audit_unique(rel regclass) returns void
language plpgsql as
$$
declare
    t text := (select relname from pg_class where oid = rel);
begin
    if public.luminate_is_exempt(t) then
        return;
    end if;
    insert into public.luminate_tenancy_audit (kind, object, detail)
    select 'unique_without_tenant', i.indexrelid::regclass::text, pg_get_indexdef(i.indexrelid)
    from pg_index i
    where i.indrelid = rel
      and i.indisunique
      and not i.indisprimary
      and i.indkey[0] is distinct from (select a.attnum from pg_attribute a where a.attrelid = rel and a.attname = 'tenant_id' and not a.attisdropped)
      and not exists (
            select 1 from public.luminate_tenancy_audit x
            where x.kind = 'unique_without_tenant' and x.object = i.indexrelid::regclass::text and x.detail = pg_get_indexdef(i.indexrelid));
end
$$;

-- A primary key the APPLICATION chooses (not a sequence/identity), made of integer columns that are not themselves foreign
-- keys. Two tenants can legitimately both choose the same value (e.g. Wellms' `config` table always uses id = 1), so a global
-- PK would make the second tenant's insert fail. Pivot tables (every PK column is a foreign key) derive their values from
-- tenant-owned parents and are not affected.
create or replace function public.luminate_is_app_assigned_pk(pk oid) returns boolean
language sql stable as
$$
    select not exists (
               select 1
               from pg_constraint con
               join pg_attribute a on a.attrelid = con.conrelid and a.attnum = any (con.conkey)
               left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
               where con.oid = pk
                 and (pg_get_expr(d.adbin, d.adrelid) like 'nextval%' or a.attidentity <> '' or a.attgenerated <> ''))
       and not exists (
               select 1
               from pg_constraint con
               join pg_attribute a on a.attrelid = con.conrelid and a.attnum = any (con.conkey)
               where con.oid = pk and a.atttypid not in ('int2'::regtype, 'int4'::regtype, 'int8'::regtype))
       and not coalesce((
               select bool_and(exists (select 1 from pg_constraint f
                                       where f.conrelid = con.conrelid and f.contype = 'f' and a.attnum = any (f.conkey)))
               from pg_constraint con
               join pg_attribute a on a.attrelid = con.conrelid and a.attnum = any (con.conkey)
               where con.oid = pk), false)
$$;

create or replace function public.luminate_audit_pk(rel regclass) returns void
language plpgsql as
$$
declare
    t text := (select relname from pg_class where oid = rel);
begin
    if public.luminate_is_exempt(t) then
        return;
    end if;
    insert into public.luminate_tenancy_audit (kind, object, detail)
    select 'app_pk_without_tenant', rel::text, pg_get_constraintdef(con.oid)
    from pg_constraint con
    where con.conrelid = rel and con.contype = 'p'
      and public.luminate_is_app_assigned_pk(con.oid)
      and con.conkey[1] is distinct from (select a.attnum from pg_attribute a where a.attrelid = rel and a.attname = 'tenant_id' and not a.attisdropped)
      and not exists (select 1 from public.luminate_tenancy_audit x where x.kind = 'app_pk_without_tenant' and x.object = rel::text);
end
$$;

-- Rewrite every application-assigned PK on a tenant-owned table to (tenant_id, <original columns>).
create or replace function public.luminate_rewrite_primary_keys() returns setof text
language plpgsql as
$$
declare
    r    record;
    ndef text;
begin
    for r in
        select con.conrelid::regclass as rel, con.conname, pg_get_constraintdef(con.oid) as def
        from pg_constraint con
        join pg_class c on c.oid = con.conrelid
        join pg_namespace ns on ns.oid = c.relnamespace
        where ns.nspname = 'public' and con.contype = 'p' and c.relkind = 'r'
          and not public.luminate_is_exempt(c.relname)
          and exists (select 1 from pg_attribute a where a.attrelid = con.conrelid and a.attname = 'tenant_id' and not a.attisdropped)
          and con.conkey[1] is distinct from (select a.attnum from pg_attribute a where a.attrelid = con.conrelid and a.attname = 'tenant_id' and not a.attisdropped)
          and public.luminate_is_app_assigned_pk(con.oid)
        order by c.relname
    loop
        ndef := regexp_replace(r.def, 'PRIMARY KEY \(', 'PRIMARY KEY (tenant_id, ');
        -- fails loudly if rows without a tenant exist: they would be invisible orphans and must be dealt with first
        execute format('alter table %s alter column tenant_id set not null', r.rel);
        execute format('alter table %s drop constraint %I', r.rel, r.conname);
        execute format('alter table %s add constraint %I %s', r.rel, r.conname, ndef);
        insert into public.luminate_tenancy_audit (kind, object, detail) values ('pk_rewritten', r.conname, r.def || '  ->  ' || ndef);
        return next r.rel::text || ' ' || r.conname || ': ' || r.def || ' -> ' || ndef;
    end loop;
end
$$;

create or replace function public.luminate_enroll_all() returns integer
language plpgsql as
$$
declare
    r record;
    n integer := 0;
begin
    for r in
        select c.oid::regclass as rel
        from pg_class c join pg_namespace ns on ns.oid = c.relnamespace
        where ns.nspname = 'public' and c.relkind in ('r', 'p') and not public.luminate_is_exempt(c.relname)
        order by c.relname
    loop
        perform public.luminate_enroll_table(r.rel);
        n := n + 1;
    end loop;
    insert into public.luminate_tenancy_audit (kind, object, detail) values ('enroll_all', 'public', n || ' tables');
    return n;
end
$$;

-- Rewrite every non-PK unique index/constraint on a tenant-owned table to lead with tenant_id (composite uniques).
create or replace function public.luminate_rewrite_uniques() returns setof text
language plpgsql as
$$
declare
    r    record;
    cdef text;
    ndef text;
begin
    for r in
        select i.indexrelid, i.indrelid::regclass as rel, c.relname,
               ic.relname as idxname,
               con.conname, con.oid as conoid
        from pg_index i
        join pg_class c  on c.oid = i.indrelid
        join pg_class ic on ic.oid = i.indexrelid
        join pg_namespace ns on ns.oid = c.relnamespace
        left join pg_constraint con on con.conindid = i.indexrelid and con.contype = 'u'
        where ns.nspname = 'public' and i.indisunique and not i.indisprimary
          and not public.luminate_is_exempt(c.relname)
          and i.indkey[0] is distinct from (select a.attnum from pg_attribute a where a.attrelid = i.indrelid and a.attname = 'tenant_id' and not a.attisdropped)
          and exists (select 1 from pg_attribute a where a.attrelid = i.indrelid and a.attname = 'tenant_id' and not a.attisdropped)
        order by c.relname, ic.relname
    loop
        if r.conoid is not null then
            cdef := pg_get_constraintdef(r.conoid);
            ndef := regexp_replace(cdef, 'UNIQUE( NULLS NOT DISTINCT)? \(', 'UNIQUE\1 (tenant_id, ');
            execute format('alter table %s drop constraint %I', r.rel, r.conname);
            execute format('alter table %s add constraint %I %s', r.rel, r.conname, ndef);
            insert into public.luminate_tenancy_audit (kind, object, detail) values ('unique_rewritten', r.conname, cdef || '  ->  ' || ndef);
            return next r.rel::text || ' ' || r.conname || ': ' || cdef || ' -> ' || ndef;
        else
            cdef := pg_get_indexdef(r.indexrelid);
            ndef := regexp_replace(cdef, '\(', '(tenant_id, ');
            execute format('drop index %s', r.indexrelid::regclass);
            execute ndef;
            insert into public.luminate_tenancy_audit (kind, object, detail) values ('unique_rewritten', r.idxname, cdef || '  ->  ' || ndef);
            return next r.rel::text || ' ' || r.idxname || ': ' || cdef || ' -> ' || ndef;
        end if;
    end loop;
end
$$;

-- Current-state check used as a release gate. Empty result = healthy.
-- null_tenant_rows is only meaningful when run as the owner/superuser (the app role cannot see NULL-tenant rows).
create or replace function public.luminate_tenancy_drift() returns table (kind text, object text, detail text)
language plpgsql as
$$
declare
    r record;
    n bigint;
begin
    for r in
        select c.oid, c.relname, c.relrowsecurity
        from pg_class c join pg_namespace ns on ns.oid = c.relnamespace
        where ns.nspname = 'public' and c.relkind in ('r', 'p') and not public.luminate_is_exempt(c.relname)
        order by c.relname
    loop
        if not exists (select 1 from pg_attribute a where a.attrelid = r.oid and a.attname = 'tenant_id' and not a.attisdropped) then
            kind := 'no_tenant_id'; object := r.relname; detail := null; return next; continue;
        end if;
        if not r.relrowsecurity then
            kind := 'rls_disabled'; object := r.relname; detail := null; return next;
        end if;
        if not exists (select 1 from pg_policy p where p.polrelid = r.oid and p.polname = 'tenant_isolation') then
            kind := 'no_policy'; object := r.relname; detail := null; return next;
        end if;
        if coalesce((select pg_get_expr(d.adbin, d.adrelid) from pg_attrdef d join pg_attribute a on a.attrelid = d.adrelid and a.attnum = d.adnum
                     where d.adrelid = r.oid and a.attname = 'tenant_id'), '') not like '%luminate_tenant_id()%' then
            kind := 'no_default'; object := r.relname; detail := null; return next;
        end if;
        execute format('select count(*) from %s where tenant_id is null', r.oid::regclass) into n;
        if n > 0 then
            kind := 'null_tenant_rows'; object := r.relname; detail := n::text; return next;
        end if;
    end loop;

    for r in
        select ic.relname as idxname, c.relname, pg_get_indexdef(i.indexrelid) as def
        from pg_index i
        join pg_class c on c.oid = i.indrelid
        join pg_class ic on ic.oid = i.indexrelid
        join pg_namespace ns on ns.oid = c.relnamespace
        where ns.nspname = 'public' and i.indisunique and not i.indisprimary and not public.luminate_is_exempt(c.relname)
          and i.indkey[0] is distinct from (select a.attnum from pg_attribute a where a.attrelid = i.indrelid and a.attname = 'tenant_id' and not a.attisdropped)
    loop
        kind := 'unique_without_tenant'; object := r.relname || '.' || r.idxname; detail := r.def; return next;
    end loop;

    for r in
        select c.relname, pg_get_constraintdef(con.oid) as def
        from pg_constraint con
        join pg_class c on c.oid = con.conrelid
        join pg_namespace ns on ns.oid = c.relnamespace
        where ns.nspname = 'public' and con.contype = 'p' and c.relkind = 'r' and not public.luminate_is_exempt(c.relname)
          and public.luminate_is_app_assigned_pk(con.oid)
          and con.conkey[1] is distinct from (select a.attnum from pg_attribute a where a.attrelid = con.conrelid and a.attname = 'tenant_id' and not a.attisdropped)
    loop
        kind := 'app_pk_without_tenant'; object := r.relname; detail := r.def; return next;
    end loop;
end
$$;

-- DDL event trigger body: tables created by future Wellms upgrades become tenant-owned automatically, and any unique
-- index/constraint added without tenant_id is recorded. (The trigger itself is created by postgres/init/02-tenancy.sh.)
create or replace function public.luminate_on_ddl() returns event_trigger
language plpgsql as
$$
declare
    r   record;
    rel regclass;
begin
    for r in select * from pg_event_trigger_ddl_commands() loop
        if r.schema_name is distinct from 'public' then
            continue;
        end if;

        rel := null;
        if r.object_type = 'table' then
            rel := r.objid::regclass;
        elsif r.object_type = 'index' then
            rel := (select i.indrelid::regclass from pg_index i where i.indexrelid = r.objid);
        end if;
        if rel is null then
            continue;
        end if;

        if r.command_tag in ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO') and r.object_type = 'table' then
            if not public.luminate_is_exempt((select relname from pg_class where oid = r.objid)) then
                perform public.luminate_enroll_table(rel);
                insert into public.luminate_tenancy_audit (kind, object) values ('enrolled', rel::text);
            end if;
        end if;
        perform public.luminate_audit_unique(rel);
        perform public.luminate_audit_pk(rel);
    end loop;
end
$$;
