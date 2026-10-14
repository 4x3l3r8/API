<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Auth\TenantTokenBinding;
use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Database\AppRoleGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware, prepended so it runs before routing and before the auth guard can resolve a user.
 * Verifies X-Tenant-Context and fails closed (4xx/503) before any DB access. No default tenant.
 */
final class VerifyTenantContext
{
    public function __construct(
        private readonly ContextTokenVerifier $verifier,
        private readonly PlacementResolver $placements,
        private readonly TenantRuntime $runtime,
        private readonly TenantTokenBinding $binding,
        private readonly AppRoleGuard $roleGuard,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->runtime->deactivate();

        // First of all, before even the exempt paths: a process configured with a role that bypasses RLS serves nothing.
        try {
            $this->roleGuard->assertSafe();
        } catch (\RuntimeException $e) {
            Log::critical($e->getMessage());

            return response()->json(['message' => 'Service misconfigured.', 'code' => 'tenant_context_forbidden_db_role'], 503);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        try {
            $token = (string) $request->headers->get((string) config('luminate-tenancy.context_header'), '');
            if ($token === '') {
                throw ContextRejected::missing();
            }
            $resolved = $this->placements->resolve($this->verifier->verify($token));
            // §D.5: the user token must belong to this tenant. Explicit 403, before authentication and before any DB access.
            $this->binding->assertMatches($request->bearerToken(), $resolved->id());
        } catch (ContextRejected $e) {
            // never log the token itself
            Log::warning('tenant context rejected', [
                'code' => $e->code(),
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => $e->getMessage(), 'code' => $e->code()], $e->httpStatus);
        }

        $this->runtime->activate($resolved);
        Log::withContext(['tid' => $resolved->id()]);

        return $next($request);
    }

    // No terminate(): the tenant must stay active until the process ends so that work run after the response
    // (dispatchAfterResponse, terminating callbacks) still executes as the right tenant. State cannot leak between requests:
    // PHP-FPM discards it, and handle() resets to "no tenant" first thing on every request (Octane/Swoole is unsupported).

    private function isExempt(Request $request): bool
    {
        $patterns = (array) config('luminate-tenancy.exempt_paths', []);

        return $patterns !== [] && $request->isMethodSafe() && $request->is(...$patterns);
    }
}
