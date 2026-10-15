<?php

/**
 * Wellms side of docs/LMS_INTEGRATION_CONTRACT.md (v3) §A.1: signed tenant-context token.
 * The token is authoritative on the request path; nothing here reads a registry per request.
 */

$list = static fn (?string $v): array => array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));

// "shard-1=pgsql,shard-2=pgsql_b": opaque logical name (from the token) => Laravel connection name.
$connections = [];
foreach ($list(env('LUMINATE_TENANCY_CONNECTIONS', 'shard-1=pgsql')) as $pair) {
    [$logical, $laravel] = array_pad(explode('=', $pair, 2), 2, null);
    if ($logical !== null && $laravel !== null && $logical !== '' && $laravel !== '') {
        $connections[trim($logical)] = trim($laravel);
    }
}

return [
    'context_header' => 'X-Tenant-Context',

    // HS256 secret(s) shared only with the Luminate proxy. `previous` is accepted for zero-downtime rotation.
    'keys' => [
        'current' => env('TENANT_CONTEXT_KEY'),
        'previous' => env('TENANT_CONTEXT_KEY_PREVIOUS'),
    ],

    // exp - iat must not exceed this (seconds); leeway only applies to an iat slightly in the future.
    'max_lifetime' => 300,
    'leeway' => 30,

    // GET/HEAD paths served without a context token (e.g. Swagger UI). Leave EMPTY in production.
    'exempt_paths' => $list(env('LUMINATE_TENANCY_EXEMPT_PATHS', '')),

    // The token never carries credentials: it names a placement, and Wellms maps it from its own config.
    'connections' => $connections,
    'allowed_buckets' => $list(env('LUMINATE_TENANCY_BUCKETS', env('AWS_BUCKET', ''))),

    // Database roles that must NEVER serve request / queue-job / provisioning work (the migration owner, the bootstrap
    // superuser). The process refuses to run tenant work if its configured DB user is in this list.
    'forbidden_db_users' => $list(env('LUMINATE_TENANCY_FORBIDDEN_DB_USERS', '')),

    // PDO::PGSQL_ATTR_DISABLE_PREPARES for every pgsql connection. REQUIRED behind PgBouncer in transaction mode: pdo_pgsql's named
    // server-side prepares abort the transaction there (Luminate\Tenancy\Database\PgsqlOptions). Behaves the same over a direct connection.
    'pooler_safe_prepares' => filter_var(env('LUMINATE_DB_POOLER_SAFE_PREPARES', true), FILTER_VALIDATE_BOOLEAN),

    // Job classes that must never run on this deployment, whatever tenant or tag they carry (checked by the queue guard before
    // anything else). Contract §H: Wellms never autonomously moves money. Luminate/PortalAcademy own commerce.
    'denied_jobs' => $list(env('LUMINATE_DENIED_JOBS', 'EscolaLms\Cart\Jobs\RenewRecursiveProduct,EscolaLms\Cart\Jobs\RenewRecursiveProductUser')),

    // Scheduled events switched off (skip) after the schedule is built: a job name, or an artisan command. Defaults are
    // Wellms' commerce automation. Set the env var to a different list to override; there is no "enable all" shortcut.
    'disabled_schedules' => $list(env('LUMINATE_DISABLED_SCHEDULES', 'EscolaLms\Cart\Jobs\RenewRecursiveProduct,EscolaLms\Cart\Jobs\ExpireRecursiveProduct,cart:abandoned-event')),

    // failed_jobs is global (un-tenanted) and holds serialized tenant data: delete rows older than this (hours). 0 disables.
    'failed_jobs_retention_hours' => (int) env('LUMINATE_FAILED_JOBS_RETENTION_HOURS', 336),

    // Signed webhooks to the Luminate receiver (contract §F). Off until a URL is set; with a URL it also needs a secret.
    'webhooks' => [
        'url' => env('LUMINATE_WEBHOOK_URL'),
        'secret' => env('LUMINATE_WEBHOOK_SECRET'),
        'allow_http' => filter_var(env('LUMINATE_WEBHOOK_ALLOW_HTTP', false), FILTER_VALIDATE_BOOLEAN), // dev only
        'timeout' => (int) env('LUMINATE_WEBHOOK_TIMEOUT', 10),
        'connect_timeout' => (int) env('LUMINATE_WEBHOOK_CONNECT_TIMEOUT', 5),
        // seconds to wait after the 1st, 2nd, ... failure (the last repeats): +1m, +5m, +30m, +2h, +6h
        'backoff' => array_map('intval', $list(env('LUMINATE_WEBHOOK_BACKOFF', '60,300,1800,7200,21600'))),
        'max_age' => (int) env('LUMINATE_WEBHOOK_MAX_AGE', 86400), // give up (dead-letter) beyond this age
        'lease' => (int) env('LUMINATE_WEBHOOK_LEASE', 120),       // a claimed attempt that never reports back is retried after this
        'retention_days' => (int) env('LUMINATE_WEBHOOK_RETENTION_DAYS', 14),
        'dead_retention_days' => (int) env('LUMINATE_WEBHOOK_DEAD_RETENTION_DAYS', 90),
        // Wellms event class => contract §F event name
        'events' => [
            \EscolaLms\Courses\Events\CourseAssigned::class => 'enrolment.created',
            \EscolaLms\Courses\Events\CourseUnassigned::class => 'enrolment.removed',
            \EscolaLms\Courses\Events\TopicFinished::class => 'topic.completed',
            \EscolaLms\Courses\Events\CourseFinished::class => 'course.completed',
            \EscolaLms\TopicTypeGift\Events\QuizAttemptFinishedEvent::class => 'quiz.attempt.finished',
            \EscolaLms\TopicTypeGift\Events\QuizAttemptJournalGradeReadyEvent::class => 'quiz.grade.ready',
        ],
    ],

    // Test fixtures (probe job + dispatch command used by the isolation suite). MUST be false outside dev/test.
    'fixtures' => filter_var(env('LUMINATE_TENANCY_TEST_FIXTURES', false), FILTER_VALIDATE_BOOLEAN),

    // Local registry replica for out-of-request work (contract §A.2): which tenants exist, their status and placement. Read by
    // queue workers, the scheduler and provisioning; never on the request path (the signed context token is authoritative there).
    //   database: the shared table kept by push + reconcile (production).   file: a static JSON snapshot (tests, seeding).
    'replica_driver' => env('LUMINATE_REPLICA_DRIVER', 'database'),
    // in-process cache of the database replica, seconds. This is the bounded staleness for a running worker. 0 = every read.
    'replica_cache_ttl' => (int) env('LUMINATE_REPLICA_CACHE_TTL', 2),
    // The snapshot file: the `file` driver reads it; `luminate:registry:import` seeds an empty database from it (insert-only).
    'replica_path' => env('LUMINATE_TENANCY_REPLICA', '/etc/luminate/tenants.json'),

    // Registry sync with Luminate (contract §A.2): push on change (PUT /api/luminate/registry/tenants/{tid}) + a periodic pull.
    // Both are authenticated with HMAC using a key that must differ from the tenant-context key; unset = the endpoints answer 503.
    'registry' => [
        'keys' => [
            'current' => env('LUMINATE_REGISTRY_KEY'),
            'previous' => env('LUMINATE_REGISTRY_KEY_PREVIOUS'),
        ],
        'window' => (int) env('LUMINATE_REGISTRY_WINDOW', 300), // accepted timestamp skew, seconds
        'pull_url' => env('LUMINATE_REGISTRY_PULL_URL'),         // empty = no pull (push only)
        'allow_http' => filter_var(env('LUMINATE_REGISTRY_ALLOW_HTTP', false), FILTER_VALIDATE_BOOLEAN), // dev only
        'timeout' => (int) env('LUMINATE_REGISTRY_TIMEOUT', 10),
        'connect_timeout' => (int) env('LUMINATE_REGISTRY_CONNECT_TIMEOUT', 5),
        'reconcile_interval' => (int) env('LUMINATE_REGISTRY_RECONCILE_INTERVAL', 300), // seconds between pulls (scheduler)
        // a tenant touched within this many seconds before the snapshot's as_of is not tombstoned by it (clock skew + in-flight push)
        'skew' => (int) env('LUMINATE_REGISTRY_SKEW', 120),
        // status/health report a replica not reconciled for this long (seconds); it never blocks work (bounded staleness is allowed)
        'stale_after' => (int) env('LUMINATE_REGISTRY_STALE_AFTER', 3600),
    ],

    // Rows that Wellms MIGRATIONS insert with no tenant (so no tenant can see them); provisioning creates them per tenant.
    // Observed on Wellms 1.0.54. `_key` = columns that identify a row. Any other table that ends up with NULL-tenant rows
    // after an upgrade is flagged by the isolation suite (see tests/sql/rls_checks.py) and must be added here or handled.
    'reference_rows' => [
        'questionnaire_model_types' => [
            ['_key' => ['model_class'], 'title' => 'course', 'model_class' => 'EscolaLms\Courses\Models\Course'],
            ['_key' => ['model_class'], 'title' => 'webinar', 'model_class' => 'EscolaLms\Webinar\Models\Webinar'],
            ['_key' => ['model_class'], 'title' => 'consultations', 'model_class' => 'EscolaLms\Consultations\Models\Consultation'],
        ],
    ],

    // Tables that are NOT tenant-owned: table => reason. Mirrored into luminate_tenancy_exempt by the install migration.
    'exempt_tables' => [
        'migrations' => 'Laravel migration ledger',
        'failed_jobs' => 'global queue failure log; payloads are only read from the CLI, never exposed by the API',
        'luminate_tenancy_exempt' => 'tenancy metadata',
        'luminate_tenancy_audit' => 'tenancy metadata',
        'luminate_tenant_registry' => 'local replica of the Luminate tenant registry (contract A.2); global by design',
        'luminate_registry_meta' => 'registry reconcile bookkeeping',
    ],
];
