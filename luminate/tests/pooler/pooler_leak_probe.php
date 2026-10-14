<?php
/**
 * DEV-ONLY. Runs INSIDE the api container, as the RLS-bound app role, through whatever DB_HOST/DB_PORT point at (the pooler).
 *
 *   php pooler_leak_probe.php <iterations> <clients> <seed>             randomised interleaving; prints one JSON line
 *   php pooler_leak_probe.php control                                     session-level setting: MUST leak (proves the probe can see leaks)
 *
 * Several client connections share a tiny pool of backends. Each iteration picks a client and a tenant (or none), opens a
 * transaction, and checks the one thing that matters under transaction pooling: BEFORE this transaction sets anything, the
 * backend it was handed carries NO app.tenant_id from whoever used it last; and AFTER setting it, rows are exactly that
 * tenant's. Residual state would show up as a non-empty setting before the SET, or as another tenant's row count.
 */
declare(strict_types=1);

const TENANTS = ['t-alpha', 't-beta', 't-delta', null];

/**
 * How the application connects behind the pooler (see PgsqlOptions): PGSQL_ATTR_DISABLE_PREPARES, emulation off.
 * $mode: 'app' (default) | 'default' (PDO's named server-side prepares) | 'emulated' (client-side prepares)
 */
function connect(string $host, string $port, string $mode = 'app'): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + match ($mode) {
        'default' => [],
        'emulated' => [PDO::ATTR_EMULATE_PREPARES => true],
        default => [PDO::ATTR_EMULATE_PREPARES => false, PDO::PGSQL_ATTR_DISABLE_PREPARES => true],
    };

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, getenv('DB_DATABASE')),
        (string) getenv('DB_USERNAME'),
        (string) getenv('DB_PASSWORD'),
        $options
    );
}

function label(?string $t): string
{
    return $t ?? 'NONE';
}
if (($argv[1] ?? '') === 'prepare-control') {
    // Three PDO modes x four behaviours, each on a FRESH connection (so one failure cannot cascade into the next). Prints the matrix.
    $behaviours = [
        'destroyed statement, then another statement in the same transaction' => function (PDO $c) {
            $c->beginTransaction();
            $s = $c->query("select current_setting('app.tenant_id', true)");
            $s->fetchColumn();
            unset($s);
            $c->exec("select set_config('app.tenant_id', 't-alpha', true)");
            $c->commit();
        },
        'integer bound to a boolean column (what Laravel does for every bool)' => function (PDO $c) {
            $s = $c->prepare('select count(*) from oauth_access_tokens where revoked = ?');
            $s->bindValue(1, 0, PDO::PARAM_INT);
            $s->execute();
            $s->fetchColumn();
        },
        'string bound to an integer column' => function (PDO $c) {
            $s = $c->prepare('select count(*) from users where id = ?');
            $s->bindValue(1, '5', PDO::PARAM_STR);
            $s->execute();
            $s->fetchColumn();
        },
        'one statement object reused across three transactions' => function (PDO $c) {
            $s = $c->prepare('select count(*) from roles where id > ?');
            for ($i = 0; $i < 3; $i++) {
                $c->beginTransaction();
                $s->bindValue(1, $i, PDO::PARAM_INT);
                $s->execute();
                $s->fetchColumn();
                $c->commit();
            }
        },
    ];
    $matrix = [];
    foreach (['default', 'emulated', 'app'] as $mode) {
        foreach ($behaviours as $name => $fn) {
            $c = connect((string) getenv('DB_HOST'), (string) getenv('DB_PORT'), $mode);
            try {
                $fn($c);
                $matrix[$mode][$name] = 'ok';
            } catch (Throwable $e) {
                try { $c->rollBack(); } catch (Throwable $x) {}
                $matrix[$mode][$name] = 'FAILED: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 70);
            }
        }
    }
    echo json_encode(['mode' => 'prepare-control', 'matrix' => $matrix], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if (($argv[1] ?? '') === 'control') {
    // Session-level set_config(..., false) is what a naive implementation would do. Under transaction pooling it must leak.
    $a = connect((string) getenv('DB_HOST'), (string) getenv('DB_PORT'));
    $b = connect((string) getenv('DB_HOST'), (string) getenv('DB_PORT'));
    $a->query("select set_config('app.tenant_id', 'LEAK-CANARY', false)")->fetchAll();
    $seen = [];
    $leaked = null;
    for ($i = 0; $i < 400 && $leaked === null; $i++) {
        $b->beginTransaction();
        $row = $b->query("select current_setting('app.tenant_id', true) as seen, pg_backend_pid() as pid")->fetch(PDO::FETCH_ASSOC);
        $b->commit();
        $seen[$row['pid']] = $row['seen'];
        if ($row['seen'] === 'LEAK-CANARY') {
            $leaked = $row;
        }
    }
    // clean every backend we can reach so the canary does not outlive the probe
    $cleaned = [];
    for ($i = 0; $i < 200 && count($cleaned) < 2; $i++) {
        $row = (($i % 2) ? $a : $b)->query("select set_config('app.tenant_id', '', false) as v, pg_backend_pid() as pid")->fetch(PDO::FETCH_ASSOC);
        $cleaned[$row['pid']] = true;
    }
    echo json_encode(['mode' => 'control', 'leak_observed' => $leaked !== null, 'leaked_on_pid' => $leaked['pid'] ?? null, 'cleaned_pids' => array_keys($cleaned)]) . "\n";
    exit(0);
}

$iterations = (int) ($argv[1] ?? 1000);
$nClients = max(2, (int) ($argv[2] ?? 4));
mt_srand((int) ($argv[3] ?? 1));

// expected row counts per tenant, measured over a DIRECT connection (not the pooler)
$direct = connect('postgres', '5432');
$expect = [];
foreach (TENANTS as $t) {
    $direct->beginTransaction();
    if ($t !== null) {
        $direct->exec('select set_config(' . $direct->quote('app.tenant_id') . ', ' . $direct->quote($t) . ', true)');
    }
    $expect[label($t)] = (int) $direct->query('select count(*) from users')->fetchColumn();
    $direct->commit();
}

$clients = [];
$prepared = [];
for ($i = 0; $i < $nClients; $i++) {
    $clients[] = connect((string) getenv('DB_HOST'), (string) getenv('DB_PORT'));
}

$violations = [];
$pidTenants = [];   // backend pid => [tenant label => transactions]
$last = [];         // backend pid => label of the previous transaction THIS process ran there
$stat = ['transactions' => 0, 'pre_null' => 0, 'pre_empty' => 0, 'pre_residual' => 0, 'tenantless_after_tenant' => 0, 'tenant_after_other_tenant' => 0, 'prepared_runs' => 0, 'autocommit' => 0];

for ($i = 0; $i < $iterations; $i++) {
    $ci = mt_rand(0, $nClients - 1);
    $c = $clients[$ci];
    $t = TENANTS[mt_rand(0, 3)];
    $mode = mt_rand(0, 9);

    if ($mode >= 8) {
        // no explicit transaction: every statement is its own implicit transaction and may land on a different backend
        $t = null;
        $stat['autocommit']++;
        $row = $c->query("select current_setting('app.tenant_id', true) as pre, pg_backend_pid() as pid")->fetch(PDO::FETCH_ASSOC);
        $pre = $row['pre'];
        $seen = $pre;
        $pid = (string) $row['pid'];
        $cnt = (int) $c->query('select count(*) from users')->fetchColumn();
    } else {
        $c->beginTransaction();
        $pre = $c->query("select current_setting('app.tenant_id', true)")->fetchColumn();
        if ($t !== null) {
            $c->exec('select set_config(' . $c->quote('app.tenant_id') . ', ' . $c->quote($t) . ', true)');
        }
        $row = $c->query("select current_setting('app.tenant_id', true) as seen, pg_backend_pid() as pid")->fetch(PDO::FETCH_ASSOC);
        $seen = $row['seen'];
        $pid = (string) $row['pid'];
        if (mt_rand(0, 3) === 0) {
            usleep(mt_rand(200, 3000)); // hold the backend a moment so other processes contend for it
        }
        if ($mode >= 6) {
            // a server-side prepared statement prepared once, executed in many transactions on many backends
            $prepared[$ci] ??= $c->prepare('select count(*) from users');
            $prepared[$ci]->execute();
            $cnt = (int) $prepared[$ci]->fetchColumn();
            $stat['prepared_runs']++;
        } else {
            $cnt = (int) $c->query('select count(*) from users')->fetchColumn();
        }
        $c->commit();
    }

    $stat['transactions']++;
    $lab = label($t);
    $prev = $last[$pid] ?? null;

    if ($pre === null) {
        $stat['pre_null']++;
    } elseif ($pre === '') {
        $stat['pre_empty']++;   // the custom setting existed on this backend earlier and has since expired: SET LOCAL died with its transaction
    } else {
        $stat['pre_residual']++;
        $violations[] = ['kind' => 'residual setting before this transaction set anything', 'pid' => $pid, 'tenant' => $lab, 'found' => $pre, 'previous_here' => $prev];
    }
    if (($t === null ? ($seen !== null && $seen !== '') : $seen !== $t)) {
        $violations[] = ['kind' => 'wrong setting inside the transaction', 'pid' => $pid, 'tenant' => $lab, 'seen' => $seen];
    }
    if ($cnt !== $expect[$lab]) {
        $violations[] = ['kind' => 'row count is not this tenant\'s', 'pid' => $pid, 'tenant' => $lab, 'count' => $cnt, 'expected' => $expect[$lab]];
    }
    if ($prev !== null && $prev !== 'NONE' && $t === null) {
        $stat['tenantless_after_tenant']++;
    }
    if ($prev !== null && $prev !== 'NONE' && $t !== null && $prev !== $t) {
        $stat['tenant_after_other_tenant']++;
    }
    $last[$pid] = $lab;
    $pidTenants[$pid][$lab] = ($pidTenants[$pid][$lab] ?? 0) + 1;
}

echo json_encode(['mode' => 'interleave', 'iterations' => $iterations, 'clients' => $nClients, 'expect' => $expect, 'stat' => $stat,
    'pid_tenants' => $pidTenants, 'violations' => array_slice($violations, 0, 20), 'violation_count' => count($violations)]) . "\n";
