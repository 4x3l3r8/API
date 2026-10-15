<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Provisioning\AdminCredential;
use Luminate\Tenancy\Provisioning\AdminSecret;
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
 *   POST /api/luminate/registry/tenants/{tid}/admin-credential           -> {tenant_id, email, secret, generated, rotated_at}   (contract A.2.2)
 *
 * `provisioned` is how Luminate learns a new tenant's baseline data (roles, first admin, Passport clients) is in place, before it
 * flips the tenant to `active`.
 */
final class RegistryController
{
    public function __construct(private readonly RegistryAuth $auth, private readonly RegistryStore $store, private readonly AdminCredential $credentials)
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

    /**
     * Issue or rotate the tenant's machine-admin secret (contract A.2.2). Body: empty / `{}` (Wellms generates one and returns it ONCE) or
     * `{"secret": "..."}` (Luminate supplies it; never echoed). Every call rotates and revokes the admin's tokens. A signature can be used once.
     */
    public function adminCredential(Request $request, string $tid): JsonResponse
    {
        try {
            $this->authenticate($request);
            if (!RegistryPayload::validTenantId($tid)) {
                throw RegistryRejected::invalid('tenant id is not valid');
            }
            $supplied = null;
            $raw = $request->getContent();
            if ($raw !== '') {
                try {
                    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw RegistryRejected::invalid('body is not JSON');
                }
                if (!is_array($data) || ($data !== [] && array_is_list($data)) || array_diff(array_keys($data), ['secret']) !== []) {
                    throw RegistryRejected::invalid('body must be empty or {"secret": "..."}');
                }
                if (array_key_exists('secret', $data)) {
                    if (($problem = AdminSecret::problem($data['secret'])) !== null) {
                        throw RegistryRejected::invalid($problem);
                    }
                    $supplied = $data['secret'];
                }
            }
            // a captured request must not be able to rotate the secret again, even inside the signature window
            try {
                $fresh = Cache::add('luminate:regcred:' . hash('sha256', (string) $request->headers->get(RegistryAuth::SIGNATURE)), 1, 600);
            } catch (\Throwable) {
                throw RegistryRejected::misconfigured('the replay cache is unavailable');
            }
            if (!$fresh) {
                throw new RegistryRejected('registry_replayed', 'This signed request was already used.', 409);
            }
            $out = $this->credentials->issue($tid, $supplied);
        } catch (RegistryRejected $e) {
            return $this->refuse($request, $e);
        }
        // never the secret, never the body
        Log::info('registry admin credential issued', ['tenant' => $tid, 'generated' => $out['generated'], 'ip' => $request->ip()]);

        return response()->json([
            'tenant_id' => $tid, 'email' => $out['email'], 'secret' => $out['secret'], 'generated' => $out['generated'], 'rotated_at' => time(),
        ])->header('Cache-Control', 'no-store');
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
