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
];
