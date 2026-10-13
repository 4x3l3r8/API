<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Postgres connection that scopes every statement to the current tenant with a TRANSACTION-LOCAL setting
 * (set_config('app.tenant_id', tid, true) == SET LOCAL), so it is safe behind PgBouncer in transaction-pooling mode
 * (contract §H). Nothing is ever set at session level.
 *
 *  - inside a transaction opened through this connection: applied once, right after BEGIN;
 *  - a statement outside any transaction: wrapped in its own short transaction (BEGIN; set_config; stmt; COMMIT);
 *  - a transaction opened directly on the PDO (bypassing Laravel): applied before each statement.
 *
 * With no current tenant nothing is applied, the setting is NULL, and row-level security hides every tenant row from the
 * app role (fail closed). The resolver is a closure so this class does not depend on the container.
 */
final class TenantPostgresConnection extends PostgresConnection
{
    public const SETTING = 'app.tenant_id';

    /** @var null|Closure():?string */
    private ?Closure $tenantResolver = null;

    public function setTenantResolver(Closure $resolver): self
    {
        $this->tenantResolver = $resolver;

        return $this;
    }

    public function beginTransaction()
    {
        parent::beginTransaction();

        if ($this->transactionLevel() === 1 && ($tenant = $this->tenant()) !== null) {
            $this->applyLocal($this->getPdo(), $tenant);
        }
    }

    protected function run($query, $bindings, Closure $callback)
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return parent::run($query, $bindings, $callback);
        }

        $pdo = $this->getPdo();

        if ($this->transactionLevel() > 0) {
            return parent::run($query, $bindings, $callback);
        }

        if ($pdo->inTransaction()) {
            // transaction started on the raw PDO: the setting is not there yet
            $this->applyLocal($pdo, $tenant);

            return $this->guarded($pdo, fn () => parent::run($query, $bindings, $callback));
        }

        $pdo->beginTransaction();
        try {
            $this->applyLocal($pdo, $tenant);
            $result = $this->guarded($pdo, fn () => parent::run($query, $bindings, $callback));
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** If Laravel silently reconnected mid-statement, the retry would run without the tenant setting: refuse. */
    private function guarded(PDO $pdo, Closure $execute)
    {
        $result = $execute();
        if ($this->getPdo() !== $pdo) {
            throw new RuntimeException('Database connection was replaced while a tenant-scoped statement ran; refusing to continue.');
        }

        return $result;
    }

    private function applyLocal(PDO $pdo, string $tenant): void
    {
        // $tenant was validated against /^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/ when the context was verified; quote() anyway.
        $pdo->exec('select set_config(' . $pdo->quote(self::SETTING) . ', ' . $pdo->quote($tenant) . ', true)');
    }

    private function tenant(): ?string
    {
        return $this->tenantResolver ? ($this->tenantResolver)() : null;
    }
}
