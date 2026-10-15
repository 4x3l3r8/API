<?php
/**
 * DEV-ONLY measurement, never a design: what does the tenant-scoping connection cost END TO END for real requests?
 *
 *   LUMINATE_TENANT=t-alpha DB_HOST=postgres DB_PORT=5432 php pooler_wrapper_cost.php [repetitions]
 *
 * Runs the same real requests (through the real HTTP kernel, same statements) in two modes, interleaved to cancel drift:
 *   A  the real TenantPostgresConnection: every statement outside a transaction is wrapped in BEGIN; set_config; stmt; COMMIT
 *   B  a plain PostgresConnection with the tenant set ONCE at SESSION level: no per-statement scoping at all
 * B is only valid on a DIRECT connection (a session-level setting would leak across tenants behind a transaction pooler), which is
 * exactly why it is used here as the "no wrapper" floor and nowhere else. The difference A - B is the wrapper's cost.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Luminate\Tenancy\Database\TenantPostgresConnection;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$http = $app->make(HttpKernel::class);
$tid = (string) getenv('LUMINATE_TENANT');
$reps = (int) ($argv[1] ?? 15);

$statements = 0;
Event::listen(QueryExecuted::class, function () use (&$statements) { $statements++; });

function measure_request(HttpKernel $http, string $method, string $uri, ?string $token = null, ?array $json = null): int
{
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $json !== null ? json_encode($json) : null);
    $response = $http->handle($request);
    $http->terminate($request, $response);

    return $response->getStatusCode();
}

// Two PERSISTENT connections (no reconnect inside any timed request): 'pgsql' is the real wrapped connection, 'plain' is a plain
// PostgresConnection with the tenant set once at session level. Switching between them is just a change of default connection.
config(['database.connections.plain' => ['driver' => 'pgsql_plain'] + (array) config('database.connections.pgsql')]);
DB::extend('pgsql_plain', function ($config, $name) {
    $connector = new \Illuminate\Database\Connectors\PostgresConnector();

    return new PostgresConnection(fn () => $connector->connect($config), $config['database'], $config['prefix'] ?? '', $config);
});
$plainPdo = DB::connection('plain')->getPdo();
$plainPdo->exec('select set_config(' . $plainPdo->quote('app.tenant_id') . ', ' . $plainPdo->quote($tid) . ', false)');
DB::connection('pgsql')->getPdo();
$useWrapper = function (bool $wrapper): void {
    DB::setDefaultConnection($wrapper ? 'pgsql' : 'plain');
    \Illuminate\Database\Eloquent\Model::setConnectionResolver(app('db'));
};
$useWrapper(true);
$login = [];
$token = null;
$r = measure_request($http, 'POST', '/api/auth/login', null, ['email' => getenv('INITIAL_USER_EMAIL'), 'password' => getenv('PROBE_ADMIN_PASSWORD')]);
$request = Request::create('/api/auth/login', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode(['email' => getenv('INITIAL_USER_EMAIL'), 'password' => getenv('PROBE_ADMIN_PASSWORD')]));
$token = json_decode($http->handle($request)->getContent(), true)['data']['token'] ?? null;

$cases = [
    'GET  /api/profile/me' => ['GET', '/api/profile/me'],
    'GET  /api/admin/courses?per_page=50' => ['GET', '/api/admin/courses?per_page=50'],
    'GET  /api/admin/quiz-attempts?per_page=50' => ['GET', '/api/admin/quiz-attempts?per_page=50'],
    'GET  /api/admin/users?per_page=50' => ['GET', '/api/admin/users?per_page=50'],
];
$out = [];
foreach ($cases as $name => [$m, $u]) {
    $t = ['A' => [], 'B' => []];
    $n = ['A' => 0, 'B' => 0];
    for ($i = 0; $i < 2; $i++) { // warm-up each mode
        foreach ([true, false] as $w) { $useWrapper($w); measure_request($http, $m, $u, $token); }
    }
    for ($round = 0; $round < $reps; $round++) {
        foreach ([true, false] as $w) {
            $useWrapper($w);
            $statements = 0;
            $s = hrtime(true);
            $status = measure_request($http, $m, $u, $token);
            $ms = (hrtime(true) - $s) / 1e6;
            $k = $w ? 'A' : 'B';
            $t[$k][] = $ms;
            $n[$k] = $statements;
            if ($status !== 200) { fwrite(STDERR, "unexpected status $status for $name in mode $k\n"); }
        }
    }
    $stat = function (array $v): array { sort($v); return ['p50' => round($v[(int) (count($v) * .5)], 1), 'mean' => round(array_sum($v) / count($v), 1), 'min' => round($v[0], 1)]; };
    $out[$name] = ['statements' => $n['A'], 'statements_B' => $n['B'], 'wrapper' => $stat($t['A']), 'plain' => $stat($t['B'])];
}
echo json_encode(['tenant' => $tid, 'host' => getenv('DB_HOST'), 'reps' => $reps, 'cases' => $out]) . "\n";
