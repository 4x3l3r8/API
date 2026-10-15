<?php
/**
 * DEV-ONLY. Counts the database work of typical API requests, to turn the per-round-trip cost into a per-request cost.
 *
 *   LUMINATE_TENANT=t-alpha php pooler_query_census.php
 *
 * For each request it records: statements run OUTSIDE a Laravel transaction (each costs 4 round trips through the tenant-scoping
 * connection: BEGIN, set_config, statement, COMMIT) and statements INSIDE one (the transaction pays 1 extra round trip, once).
 * The requests go through the real HTTP kernel in this process. (The signed-context middleware is HTTP-only and this is a CLI
 * process, so the tenant comes from LUMINATE_TENANT instead; the database work is identical.)
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$http = $app->make(HttpKernel::class);

$count = ['outside' => 0, 'inside' => 0, 'tx' => 0];
Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$count) {
    $e->connection->transactionLevel() > 0 ? $count['inside']++ : $count['outside']++;
});
Event::listen(TransactionBeginning::class, function ($e) use (&$count) {
    if ($e->connection->transactionLevel() === 0) { // fires before the level is incremented: this is an OUTERMOST begin
        $count['tx']++;
    }
});

function call(HttpKernel $http, string $method, string $uri, ?string $token = null, array $json = null): array
{
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $request = Request::create($uri, $method, [], [], [], $server, $json !== null ? json_encode($json) : null);
    $response = $http->handle($request);
    $http->terminate($request, $response);

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}

$measure = function (string $name, callable $fn) use (&$count): array {
    $count = ['outside' => 0, 'inside' => 0, 'tx' => 0];
    [$status] = $fn();

    return ['request' => $name, 'status' => $status] + $count;
};

[$s, $login] = call($http, 'POST', '/api/auth/login', null, ['email' => getenv('INITIAL_USER_EMAIL'), 'password' => getenv('PROBE_ADMIN_PASSWORD')]);
$token = $login['data']['token'] ?? null;
$rows = [];
$rows[] = $measure('POST /api/auth/login', fn () => call($http, 'POST', '/api/auth/login', null, ['email' => getenv('INITIAL_USER_EMAIL'), 'password' => getenv('PROBE_ADMIN_PASSWORD')]));
$rows[] = $measure('GET  /api/profile/me', fn () => call($http, 'GET', '/api/profile/me', $token));
$rows[] = $measure('GET  /api/admin/users?per_page=50', fn () => call($http, 'GET', '/api/admin/users?per_page=50', $token));
$rows[] = $measure('GET  /api/admin/courses?per_page=50', fn () => call($http, 'GET', '/api/admin/courses?per_page=50', $token));
$rows[] = $measure('GET  /api/admin/quiz-attempts?per_page=50', fn () => call($http, 'GET', '/api/admin/quiz-attempts?per_page=50', $token));
$email = 'census-' . bin2hex(random_bytes(4)) . '@' . getenv('LUMINATE_TENANT') . '.test';
$rows[] = $measure('POST /api/admin/users (write)', fn () => call($http, 'POST', '/api/admin/users', $token, ['first_name' => 'C', 'last_name' => 'N', 'email' => $email, 'password' => 'Fake-Passw0rd!x', 'return_url' => 'http://localhost/', 'verified' => true]));

echo json_encode(['tenant' => getenv('LUMINATE_TENANT'), 'requests' => $rows]) . "\n";
