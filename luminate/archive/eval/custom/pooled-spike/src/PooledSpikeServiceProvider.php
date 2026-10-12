<?php

namespace Pooled\Spike;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\ServiceProvider;

/** Tenant = the request host (spike). Console without an explicit tenant => NO filter (documented risk). */
class TenantContext
{
    public static ?string $forced = null;

    public static function id(): ?string
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        if (app()->runningInConsole()) {
            return null;
        }
        return request()->getHost();
    }
}

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = TenantContext::id();
        if ($tenant !== null) {
            $builder->where($model->getTable() . '.tenant_id', $tenant);
        }
    }
}

class PooledSpikeServiceProvider extends ServiceProvider
{
    /** Core tables the spike adds tenant_id to. */
    public const TABLES = ['users', 'courses', 'lessons', 'topics', 'groups'];
    public const EXTENDED = ['topic_gift_quizzes', 'topic_gift_questions', 'topic_gift_quiz_attempts', 'topic_gift_attempt_answers', 'course_progress'];

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // RLS layer: tell Postgres which tenant this connection serves. No tenant (console) => '' => policies match nothing (fails CLOSED).
        if (env('POOLED_SPIKE_RLS', 'no') === 'yes') {
            \Illuminate\Support\Facades\DB::connection()->statement("select set_config('app.tenant_id', ?, false)", [TenantContext::id() ?? '']);
            // the Eloquent query cache keys on SQL text, which RLS does not change => make the cache tenant-aware too
            if (env('POOLED_SPIKE_CACHE_PREFIX', 'no') === 'yes') {
                $store = \Illuminate\Support\Facades\Cache::store()->getStore();
                if (method_exists($store, 'setPrefix')) {
                    $store->setPrefix('t_' . (TenantContext::id() ?? 'none') . '_' . config('cache.prefix') . ':');
                }
            }
        }

        // Queue layer: stamp the tenant into every job payload, restore it (GUC + context) when a worker picks the job up.
        if (env('POOLED_SPIKE_QUEUE', 'no') === 'yes') {
            \Illuminate\Support\Facades\Queue::createPayloadUsing(fn () => ['tenant_id' => TenantContext::id()]);
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) {
                $t = $event->job->payload()['tenant_id'] ?? null;
                TenantContext::$forced = $t;
                \Illuminate\Support\Facades\DB::statement("select set_config('app.tenant_id', ?, false)", [$t ?? '']);
            });
            $reset = function () {
                TenantContext::$forced = null;
                \Illuminate\Support\Facades\DB::statement("select set_config('app.tenant_id', '', false)");
            };
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessed::class, $reset);
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobFailed::class, $reset);
        }

        // debug: log every query touching "users" with the host that was in effect
        if (env('POOLED_SPIKE_SQLLOG', 'no') === 'yes') {
            \Illuminate\Support\Facades\DB::listen(function ($q) {
                if (str_contains($q->sql, '"users"')) {
                    @file_put_contents('/tmp/spike_sql.log', date('H:i:s') . ' host=' . (app()->runningInConsole() ? 'console' : request()->getHost()) . ' | ' . $q->sql . ' | ' . json_encode($q->bindings) . "\n", FILE_APPEND);
                }
            });
        }

        // debug route: who does the guard resolve, and what tenant context was in effect?
        \Illuminate\Support\Facades\Route::middleware('auth:api')->get('/api/_spike/whoami', function () {
            $u = auth('api')->user();
            return response()->json([
                'tenant_ctx' => TenantContext::id(),
                'host' => request()->getHost(),
                'user_class' => $u ? get_class($u) : null,
                'user_id' => $u?->getKey(),
                'user_tenant' => $u?->tenant_id,
                'direct_find_scoped' => \App\Models\User::find($u?->getKey())?->getKey(),
                'guard_already_had_user_when_scope_registered' => PooledSpikeServiceProvider::$userResolvedBeforeScope,
            ]);
        });

        if (env('POOLED_SPIKE_EARLY', 'no') !== 'yes') {
            $this->registerScopes();
        }
    }

    public function register(): void
    {
        if (env('POOLED_SPIKE_EARLY', 'no') === 'yes') {
            $this->registerScopes();
        }
    }

    public static ?bool $userResolvedBeforeScope = null;

    private function registerScopes(): void
    {
        try {
            self::$userResolvedBeforeScope = \Illuminate\Support\Facades\Auth::guard('api')->hasUser();
        } catch (\Throwable $e) {
            self::$userResolvedBeforeScope = null;
        }
        $set = env('POOLED_SPIKE_SET', 'none'); // none | partial | full
        if ($set === 'none') {
            return;
        }
        $models = [
            \EscolaLms\Courses\Models\Course::class,
            \EscolaLms\Courses\Models\Lesson::class,
            \EscolaLms\Courses\Models\Topic::class,
            \EscolaLms\Auth\Models\Group::class,
            \App\Models\User::class,           // the model the 'api' guard uses
        ];
        if ($set === 'full' || $set === 'extended') {
            // discover EVERY concrete model class whose table is in scope (users alone has 16 classes)
            $tables = $set === 'extended' ? array_merge(self::TABLES, self::EXTENDED) : self::TABLES;
            $models = [];
            $files = array_merge(
                glob(base_path('vendor/escolalms/*/src/Models/*.php')) ?: [],
                glob(base_path('vendor/escolalms/*/src/Models/*/*.php')) ?: [],
                glob(base_path('vendor/escolalms/*/src/Models/*/*/*.php')) ?: [],
                glob(base_path('app/Models/*.php')) ?: []
            );
            foreach ($files as $file) {
                $src = file_get_contents($file);
                if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns) || !preg_match('/^class\s+(\w+)/m', $src, $cl)) {
                    continue;
                }
                $class = $ns[1] . '\\' . $cl[1];
                try {
                    // newInstanceWithoutConstructor: `new $class()` BOOTS the model, and some boot routines resolve
                    // the authenticated user before our scopes exist (found the hard way, see evidence/p12).
                    if (class_exists($class) && is_subclass_of($class, Model::class) && in_array((new \ReflectionClass($class))->newInstanceWithoutConstructor()->getTable(), $tables, true)) {
                        $models[] = $class;
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        foreach (array_unique($models) as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $class::addGlobalScope(new TenantScope());
            $class::creating(function (Model $m) {
                if ($m->getAttribute('tenant_id') === null && TenantContext::id() !== null) {
                    $m->setAttribute('tenant_id', TenantContext::id());
                }
            });
        }
    }
}