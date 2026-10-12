-- Pooled spike, RLS layer. Tenant = session setting app.tenant_id (set per connection by the app).
-- 1) remove the polluted cross-tenant pivot created earlier so the RLS test starts clean
delete from course_user where user_id=2 and course_id=3;
-- 2) add tenant_id to pivots that have no model/columns, backfill from the parent
alter table course_user   add column if not exists tenant_id varchar(100);
alter table course_group  add column if not exists tenant_id varchar(100);
alter table group_user    add column if not exists tenant_id varchar(100);
alter table course_author add column if not exists tenant_id varchar(100);
update course_user   cu set tenant_id = c.tenant_id from courses c where c.id = cu.course_id and cu.tenant_id is null;
update course_group  cg set tenant_id = c.tenant_id from courses c where c.id = cg.course_id and cg.tenant_id is null;
update group_user    gu set tenant_id = g.tenant_id from groups  g where g.id = gu.group_id  and gu.tenant_id is null;
update course_author ca set tenant_id = c.tenant_id from courses c where c.id = ca.course_id and ca.tenant_id is null;
-- 3) default every tenant_id from the session setting (stamps ALL write paths: Eloquent, DB::table, raw, pivots) and enable FORCED RLS
do $$
declare t text;
begin
  foreach t in array array['users','courses','lessons','topics','groups','course_user','course_group','group_user','course_author',
                           'topic_gift_quizzes','topic_gift_questions','topic_gift_quiz_attempts','topic_gift_attempt_answers','course_progress']
  loop
    execute format('alter table %I alter column tenant_id set default current_setting(''app.tenant_id'', true)', t);
    execute format('alter table %I enable row level security', t);
    execute format('alter table %I force row level security', t);
    execute format('drop policy if exists tenant_isolation on %I', t);
    execute format('create policy tenant_isolation on %I using (tenant_id = current_setting(''app.tenant_id'', true)) with check (tenant_id = current_setting(''app.tenant_id'', true))', t);
  end loop;
end $$;
select relname, relrowsecurity as rls, relforcerowsecurity as forced from pg_class where relname in ('users','courses','course_user','topic_gift_quiz_attempts') order by 1;
