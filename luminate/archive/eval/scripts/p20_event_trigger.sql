-- DDL event trigger: any NEW table created in public automatically gets tenant_id + RLS + policy (guardrail for upgrades)
create or replace function public.pooled_auto_tenant() returns event_trigger language plpgsql as $$
declare r record; tname text;
begin
  for r in select * from pg_event_trigger_ddl_commands() where command_tag = 'CREATE TABLE' and schema_name = 'public' loop
    tname := split_part(r.object_identity, '.', 2);
    if tname in ('migrations') then continue; end if;
    execute format('alter table %s add column if not exists tenant_id varchar(100) default current_setting(''app.tenant_id'', true)', r.object_identity);
    execute format('alter table %s enable row level security', r.object_identity);
    execute format('alter table %s force row level security', r.object_identity);
    execute format('create policy tenant_isolation on %s using (tenant_id = current_setting(''app.tenant_id'', true)) with check (tenant_id = current_setting(''app.tenant_id'', true))', r.object_identity);
  end loop;
end $$;
drop event trigger if exists pooled_auto_tenant_trg;
create event trigger pooled_auto_tenant_trg on ddl_command_end when tag in ('CREATE TABLE') execute function public.pooled_auto_tenant();
-- simulate a future Wellms upgrade adding a table (as the app owner role, like artisan migrate would)
set role pooled;
create table public.future_wellms_table (id serial primary key, note text);
reset role;
select relname, relrowsecurity as rls, relforcerowsecurity as forced from pg_class where relname='future_wellms_table';
select column_name, column_default from information_schema.columns where table_name='future_wellms_table' and column_name='tenant_id';
select policyname from pg_policies where tablename='future_wellms_table';
-- behaviour: two tenants write and read
set role pooled;
select set_config('app.tenant_id','pooled-c.localhost',false);
insert into public.future_wellms_table(note) values ('written by C (tenant_id auto-stamped)');
select set_config('app.tenant_id','pooled-d.localhost',false);
insert into public.future_wellms_table(note) values ('written by D');
select 'D sees rows: ' || count(*) from public.future_wellms_table;
select set_config('app.tenant_id','',false);
select 'no tenant sees rows: ' || count(*) from public.future_wellms_table;
reset role;
select 'superuser (bypasses RLS) sees rows: ' || count(*) from public.future_wellms_table;
drop table public.future_wellms_table;
drop event trigger pooled_auto_tenant_trg;
