<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Provisioning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\TenantRuntime;
use Luminate\Tenancy\Registry\RegistryRejected;
use Luminate\Tenancy\Registry\RegistryStore;
use Luminate\Tenancy\Replica\TenantReplica;

/**
 * Issue or rotate a tenant's machine-admin credential (contract A.2.2). The admin is a service account the Luminate proxy uses to manage
 * the tenant; no human logs in as it. Provisioning leaves it WITHOUT a usable password; the only way to get one is this call, which is made
 * over the signed registry channel. Wellms keeps no copy: only the bcrypt hash in `users`.
 *
 * Runs as the RLS-bound application role, inside the tenant (so it can only ever touch that tenant's users and tokens).
 */
final class AdminCredential
{
    public function __construct(
        private readonly RegistryStore $registry,
        private readonly TenantReplica $replica,
        private readonly TenantRuntime $runtime,
    ) {
    }

    /**
     * @param string|null $supplied a secret Luminate chose (already validated by AdminSecret::problem), or null to have one generated
     *
     * @return array{email:string,secret:?string,generated:bool}
     *
     * @throws RegistryRejected 404 unknown tenant, 409 not provisioned / not servable here
     */
    public function issue(string $tid, ?string $supplied): array
    {
        $row = $this->registry->find($tid);
        if ($row === null || $row['status'] === 'removed') {
            throw new RegistryRejected('registry_tenant_unknown', 'Unknown tenant.', 404);
        }
        if (!in_array($row['status'], ['provisioning', 'active'], true) || $row['provisioned_at'] === null) {
            throw new RegistryRejected('tenant_not_provisioned', 'The tenant is not provisioned (or not in a state that can be managed).', 409);
        }
        try {
            $resolved = $this->replica->resolve($tid, ['provisioning', 'active']);
        } catch (ContextRejected) {
            throw new RegistryRejected('tenant_not_provisioned', 'The tenant is not servable by this deployment.', 409);
        }

        $secret = $supplied ?? AdminSecret::generate();
        $email = (string) config('luminate-tenancy.machine_admin_email');

        $this->runtime->activate($resolved);
        try {
            DB::transaction(function () use ($tid, $email, $secret): void {
                // explicit tenant filter as well as RLS: never rely on one mechanism for an update that changes who can log in
                $admin = DB::table('users')->where('tenant_id', $tid)->where('email', $email)->orderBy('id')->lockForUpdate()->first();
                if ($admin === null) {
                    throw new RegistryRejected('tenant_not_provisioned', 'The tenant has no machine admin yet.', 409);
                }
                DB::table('users')->where('tenant_id', $tid)->where('id', $admin->id)->update(['password' => Hash::make($secret), 'updated_at' => now()]);

                // rotation must really cut off the old secret: every token the previous one produced dies with it
                $tokens = DB::table('oauth_access_tokens')->where('tenant_id', $tid)->where('user_id', $admin->id)->pluck('id')->all();
                if ($tokens !== []) {
                    DB::table('oauth_access_tokens')->where('tenant_id', $tid)->whereIn('id', $tokens)->update(['revoked' => true]);
                    DB::table('oauth_refresh_tokens')->where('tenant_id', $tid)->whereIn('access_token_id', $tokens)->update(['revoked' => true]);
                }
            });
        } finally {
            $this->runtime->deactivate();
        }

        return ['email' => $email, 'secret' => $supplied === null ? $secret : null, 'generated' => $supplied === null];
    }
}
