<?php
/**
 * DEV-ONLY. Runs INSIDE the api container through the REAL Laravel stack (TenantPostgresConnection), as a tenant:
 *
 *   LUMINATE_TENANT=t-alpha php pooler_statement_probe.php <iterations> '<expected users per tenant json>' <seed>
 *
 * It exercises exactly the paths contract section H worries about under transaction pooling:
 *   - a statement OUTSIDE any explicit transaction (the connection wraps it in BEGIN; set_config; stmt; COMMIT), where every
 *     statement may land on a different backend;
 *   - several statements inside one DB::transaction (they must share one backend and one setting);
 *   - Eloquent and bound parameters (server-side prepared statements across backends);
 *   - a raw PDO read that BYPASSES the wrapper, straight after: the tenant setting must not linger anywhere.
 * Prints one JSON line.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tid = (string) getenv('LUMINATE_TENANT');
$iterations = (int) ($argv[1] ?? 1000);
$expectUsers = (int) (json_decode((string) ($argv[2] ?? '{}'), true)[$tid] ?? -1);
mt_srand((int) ($argv[3] ?? 1));

$violations = [];
$pids = [];
$kinds = array_fill(0, 7, 0);
$bad = function (string $what, array $detail = []) use (&$violations, $tid): void {
    $violations[] = ['what' => $what, 'tenant' => $tid] + $detail;
};
$probe = "select current_setting('app.tenant_id', true) as t, pg_backend_pid() as pid";

for ($i = 0; $i < $iterations; $i++) {
    $k = mt_rand(0, 6);
    $kinds[$k]++;
    try {
        switch ($k) {
            case 0: // a plain statement outside any transaction
                $n = DB::table('users')->count();
                $n === $expectUsers || $bad('count outside a transaction is not this tenant\'s', ['got' => $n, 'expected' => $expectUsers]);
                break;
            case 1: // which setting and backend does a wrapped single statement see?
                $r = DB::selectOne($probe);
                $pids[$r->pid] = ($pids[$r->pid] ?? 0) + 1;
                $r->t === $tid || $bad('wrapped statement saw the wrong tenant setting', ['saw' => $r->t, 'pid' => $r->pid]);
                break;
            case 2: // one explicit transaction: one backend, one setting, for every statement in it
                DB::transaction(function () use ($probe, $tid, $expectUsers, $bad, &$pids) {
                    $a = DB::selectOne($probe);
                    usleep(mt_rand(0, 2000));
                    $b = DB::selectOne($probe);
                    $n = DB::table('users')->count();
                    $pids[$a->pid] = ($pids[$a->pid] ?? 0) + 1;
                    $a->pid === $b->pid || $bad('statements of ONE transaction ran on different backends', ['a' => $a->pid, 'b' => $b->pid]);
                    ($a->t === $tid && $b->t === $tid) || $bad('transaction saw the wrong tenant setting', ['a' => $a->t, 'b' => $b->t]);
                    $n === $expectUsers || $bad('count inside a transaction is not this tenant\'s', ['got' => $n, 'expected' => $expectUsers]);
                });
                break;
            case 3: // Eloquent
                $n = \App\Models\User::count();
                $n === $expectUsers || $bad('Eloquent count is not this tenant\'s', ['got' => $n, 'expected' => $expectUsers]);
                break;
            case 4: // bound parameters = server-side prepared statements across backends
                $rows = DB::select('select count(*) as c from users where email like ? and id > ?', ['%@' . $tid . '.test', 0]);
                (int) $rows[0]->c <= $expectUsers || $bad('bound-parameter query returned more than the tenant owns', ['got' => (int) $rows[0]->c]);
                break;
            case 5: // bypass the wrapper entirely: nothing may linger on whatever backend this lands on
                $v = DB::connection()->getPdo()->query("select current_setting('app.tenant_id', true)")->fetchColumn();
                ($v === null || $v === '') || $bad('RESIDUAL tenant setting visible to a raw read after wrapped statements', ['saw' => $v]);
                break;
            case 6: // a raw read of tenant data with NO setting must see nothing (fail closed)
                $n = (int) DB::connection()->getPdo()->query('select count(*) from users')->fetchColumn();
                $n === 0 || $bad('raw read with no tenant setting saw tenant rows', ['got' => $n]);
                break;
        }
    } catch (\Throwable $e) {
        $bad('exception', ['kind' => $k, 'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 160)]);
    }
}

echo json_encode(['tenant' => $tid, 'iterations' => $iterations, 'kinds' => $kinds, 'distinct_backends' => count($pids), 'backends' => $pids,
    'violation_count' => count($violations), 'violations' => array_slice($violations, 0, 15)]) . "\n";
