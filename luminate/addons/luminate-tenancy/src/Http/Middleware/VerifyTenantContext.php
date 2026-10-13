<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use Luminate\Tenancy\Context\PlacementResolver;
use Luminate\Tenancy\Context\TenantRuntime;
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
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->runtime->deactivate();

        if ($this->isExempt($request)) {
            return $next($request);
        }

        try {
            $token = (string) $request->headers->get((string) config('luminate-tenancy.context_header'), '');
            if ($token === '') {
                throw ContextRejected::missing();
            }
            $resolved = $this->placements->resolve($this->verifier->verify($token));
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

    public function terminate(Request $request, Response $response): void
    {
        $this->runtime->deactivate();
    }

    private function isExempt(Request $request): bool
    {
        $patterns = (array) config('luminate-tenancy.exempt_paths', []);

        return $patterns !== [] && $request->isMethodSafe() && $request->is(...$patterns);
    }
}
