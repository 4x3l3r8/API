<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Database;

use PDO;

/**
 * PDO options that make Laravel safe behind PgBouncer in TRANSACTION pooling mode (contract section H).
 *
 * The problem, found live: pdo_pgsql's default is a NAMED server-side prepared statement, and it sends a SQL-level
 * `DEALLOCATE pdo_stmt_...` whenever the statement object is destroyed. PgBouncer has already renamed that statement for the
 * backend, so the backend answers "does not exist", the surrounding transaction is aborted, and the next statement fails with
 * SQLSTATE 25P02. The same code works over a direct connection.
 *
 * The obvious fix is wrong: emulated (client-side) prepares make PDO inline Laravel's integer bindings as integer LITERALS, and
 * Laravel casts every PHP bool to int, so `revoked = 0` against a boolean column fails ("operator does not exist: boolean =
 * integer"). That broke every login, over a direct connection too.
 *
 * The right fix: PDO::PGSQL_ATTR_DISABLE_PREPARES. The statement and its parameters travel together in one unnamed
 * extended-protocol call (PQexecParams): no named statement exists, so there is nothing to deallocate and nothing for the pooler
 * to track, AND Postgres still infers parameter types from the column, exactly as it does today. Verified through the pooler and
 * directly against destroyed/reused statements and int-to-boolean and string-to-int bindings.
 *
 * Pure function so it can be unit-tested without the framework.
 */
final class PgsqlOptions
{
    /**
     * @param array<string,array<string,mixed>> $connections Laravel's `database.connections`
     * @return array<string,array<string,mixed>> the same, with pooler-safe options forced on for every pgsql connection
     */
    public static function forTransactionPooling(array $connections): array
    {
        foreach ($connections as $name => $connection) {
            if (($connection['driver'] ?? null) !== 'pgsql') {
                continue;
            }
            $options = isset($connection['options']) && is_array($connection['options']) ? $connection['options'] : [];
            // emulation must be explicitly OFF: it would win over the setting below and reintroduce the int-vs-boolean failure
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            // the constant exists whenever pdo_pgsql does; without the extension there is no pgsql connection to protect
            if (defined('PDO::PGSQL_ATTR_DISABLE_PREPARES')) {
                $options[constant('PDO::PGSQL_ATTR_DISABLE_PREPARES')] = true;
            }
            $connections[$name]['options'] = $options;
        }

        return $connections;
    }
}
