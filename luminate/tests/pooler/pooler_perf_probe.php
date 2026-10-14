<?php
/**
 * DEV-ONLY micro-benchmark of what the tenant-scoping connection costs per statement. Runs INSIDE the api container:
 *
 *   LUMINATE_TENANT=t-alpha php pooler_perf_probe.php <iterations>             (DB_HOST/DB_PORT decide: direct or through the pooler)
 *
 * Variants, all against the same query on a tenant-owned table under RLS:
 *   pdo4_current     the exact sequence the connection class runs today, in raw PDO: beginTransaction, set_config, statement, commit
 *   raw_select1      one round trip: the cost of the network + the driver alone
 *   unscoped         the query with NO scoping at all (no tenant setting: RLS yields 0 rows, same plan): the floor for "no wrapper"
 *   wrapped          the REAL connection class, statement outside a transaction: BEGIN, set_config, statement, COMMIT = 4 round trips
 *   wrapped_tx10     ten statements in ONE DB::transaction: BEGIN + set_config once, then 10 statements, COMMIT; reported per statement
 *   merged3          what a cheaper wrapper could do: "BEGIN; set_config" in ONE round trip, statement, COMMIT = 3 round trips
 *   merged1          the theoretical floor: BEGIN; set_config; statement; COMMIT sent as ONE multi-statement round trip
 * Prints one JSON line of latencies in microseconds.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tid = (string) getenv('LUMINATE_TENANT');
$n = (int) ($argv[1] ?? 2000);
$warm = (int) min(300, $n / 5);
$pdo = DB::connection()->getPdo();
$id = (int) DB::table('users')->min('id');
if ($tid === '' || $id < 1) {
    fwrite(STDERR, "needs LUMINATE_TENANT and at least one user\n");
    exit(2);
}
$q = $pdo->quote($tid);
$sql = 'select id, email from users where id = ' . $id;

$variants = [
    'raw_select1' => fn () => $pdo->query('select 1')->fetchColumn(),
    'unscoped' => fn () => $pdo->query($sql)->fetchAll(),
    // the EXACT sequence the connection class runs today, in raw PDO, so the protocol cost can be compared like for like with `unscoped`
    // (the `wrapped` variant also pays Laravel's query-builder cost, which is the same with or without scoping)
    'pdo4_current' => function () use ($pdo, $q, $sql) {
        $pdo->beginTransaction();
        $pdo->exec("select set_config('app.tenant_id', $q, true)");
        $r = $pdo->query($sql)->fetchAll();
        $pdo->commit();

        return $r;
    },
    'wrapped' => fn () => DB::table('users')->where('id', $id)->first(),
    'merged3' => function () use ($pdo, $q, $sql) {
        $pdo->exec("begin; select set_config('app.tenant_id', $q, true)");
        $r = $pdo->query($sql)->fetchAll();
        $pdo->exec('commit');

        return $r;
    },
    // simple protocol (exec): the extended protocol used by the pooler-safe PDO mode refuses several statements in one message
    'merged1' => fn () => $pdo->exec("begin; select set_config('app.tenant_id', $q, true); $sql; commit"),
];

$out = [];
foreach ($variants as $name => $fn) {
    for ($i = 0; $i < $warm; $i++) {
        $fn();
    }
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $s = hrtime(true);
        $fn();
        $t[] = (hrtime(true) - $s) / 1000;
    }
    $out[$name] = $t;
}

// ten statements in one transaction, reported PER STATEMENT
$t = [];
for ($i = 0; $i < $warm; $i++) {
    DB::transaction(function () use ($id) { for ($k = 0; $k < 10; $k++) { DB::table('users')->where('id', $id)->first(); } });
}
for ($i = 0; $i < (int) ($n / 5); $i++) {
    $s = hrtime(true);
    DB::transaction(function () use ($id) { for ($k = 0; $k < 10; $k++) { DB::table('users')->where('id', $id)->first(); } });
    $t[] = (hrtime(true) - $s) / 1000 / 10;
}
$out['wrapped_tx10'] = $t;

$t = [];
for ($i = 0; $i < (int) ($n / 5); $i++) {
    $s = hrtime(true);
    $pdo->beginTransaction();
    $pdo->exec("select set_config('app.tenant_id', $q, true)");
    for ($k = 0; $k < 10; $k++) {
        $pdo->query($sql)->fetchAll();
    }
    $pdo->commit();
    $t[] = (hrtime(true) - $s) / 1000 / 10;
}
$out['pdo_tx10'] = $t;   // raw PDO, one transaction of ten statements, per statement

$stat = function (array $v): array {
    sort($v);
    $c = count($v);

    return ['n' => $c, 'mean' => round(array_sum($v) / $c, 1), 'p50' => round($v[(int) ($c * .5)], 1), 'p95' => round($v[(int) ($c * .95)], 1), 'p99' => round($v[min($c - 1, (int) ($c * .99))], 1)];
};
echo json_encode(['tenant' => $tid, 'host' => (string) getenv('DB_HOST'), 'n' => $n, 'us' => array_map($stat, $out)]) . "\n";
