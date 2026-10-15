<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Registry\RegistryAuth;
use Luminate\Tenancy\Registry\RegistryPayload;
use Luminate\Tenancy\Registry\RegistryRejected;
use Luminate\Tenancy\Registry\RegistryRules;
use Luminate\Tenancy\Registry\RegistryStore;

/**
 * Registry push (contract §A.2): Luminate -> Wellms, on every registry change. These routes carry NO tenant context (they are
 * what creates tenants) so they are authenticated on their own: an HMAC over timestamp, method, path and body with a key that is
 * not the tenant-context key. Every refusal is JSON with a stable `code`, and nothing here touches tenant-owned tables.
 *
 *   PUT /api/luminate/registry/tenants/{tid}   {status, rev, placement}  -> {outcome: applied|unchanged|stale}   (409 conflict)
 *   GET /api/luminate/registry/tenants/{tid}                             -> {tenant_id, status, rev, placement, provisioned, ...}
 *   GET /api/luminate/registry/tenants                                   -> {tenants: [...]}   (drift checks)
 *
 * `provisioned` is how Luminate learns a new tenant's baseline data (roles, first admin, Passport clients) is in place, before it
 * flips the tenant to `active`.
 */
final class RegistryController
{
    public function __construct(private readonly RegistryAuth $auth, private readonly RegistryStore $store)
    {
    }

    public function upsert(Request $request, string $tid): JsonResponse
    {
        try {
            $this->authenticate($request);
            if (!RegistryPayload::validTenantId($tid)) {
                throw RegistryRejected::invalid('tenant id is not valid');
            }
            try {
                $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw RegistryRejected::invalid('body is not JSON');
            }
            if (is_array($data)) {
                if (isset($data['tenant_id']) && $data['tenant_id'] !== $tid) {
                    throw RegistryRejected::invalid('body tenant_id does not match the path');
                }
                unset($data['tenant_id']);
            }
            $entry = RegistryPayload::parse($data);

            $outcome = $this->store->apply($tid, $entry, RegistryRules::SOURCE_PUSH, time());
        } catch (RegistryRejected $e) {
            return $this->refuse($request, $e);
        }
        Log::info('registry push', ['tenant' => $tid, 'outcome' => $outcome, 'rev' => $entry['rev'], 'status' => $entry['status']]);
        $stored = $this->store->find($tid);

        return response()->json(
            ['tenant_id' => $tid, 'outcome' => $outcome, 'status' => $stored['status'] ?? null, 'rev' => $stored['rev'] ?? null],
            $outcome === RegistryRules::CONFLICT ? 409 : 200
        );
    }

    public function show(Request $request, string $tid): JsonResponse
    {
        try {
            $this->authenticate($request);
        } catch (RegistryRejected $e) {
            return $this->refuse($request, $e);
        }
        $row = RegistryPayload::validTenantId($tid) ? $this->store->find($tid) : null;

        return $row === null
            ? response()->json(['message' => 'Unknown tenant.', 'code' => 'registry_tenant_unknown'], 404)
            : response()->json(self::present($row));
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $this->authenticate($request);
        } catch (RegistryRejected $e) {
            return $this->refuse($request, $e);
        }

        return response()->json(['tenants' => array_values(array_map([self::class, 'present'], $this->store->all()))]);
    }

    /** @throws RegistryRejected */
    private function authenticate(Request $request): void
    {
        $this->auth->verifyRequest(
            $request->headers->get(RegistryAuth::TIMESTAMP),
            $request->headers->get(RegistryAuth::SIGNATURE),
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getContent()
        );
    }

    private function refuse(Request $request, RegistryRejected $e): JsonResponse
    {
        // never log the signature, the key or the body
        Log::warning('registry request refused', ['code' => $e->reason, 'path' => $request->path(), 'method' => $request->method(), 'ip' => $request->ip()]);

        return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->httpStatus);
    }

    /** @param array<string,mixed> $row */
    private static function present(array $row): array
    {
        return [
            'tenant_id' => $row['tenant_id'],
            'status' => $row['status'],
            'rev' => $row['rev'],
            'placement' => $row['placement'],
            'provisioned' => $row['provisioned_at'] !== null,
            'provisioned_at' => $row['provisioned_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
