<?php
/**
 * DEV-ONLY probe for the Batch 5 operational guarantees. Runs INSIDE the api container:
 *
 *   docker exec -u www-data luminate-wellms-api-1 php /tmp/ops_probe.php schedule
 *   docker exec -u www-data luminate-wellms-api-1 php /tmp/ops_probe.php untenanted-event
 *   docker exec -e LUMINATE_TENANT=t-alpha -u www-data luminate-wellms-api-1 php /tmp/ops_probe.php dispatch-denied
 *
 * Prints one JSON object.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

if ($mode === 'schedule') {
    // Which scheduled events would actually run? filtersPass() is what schedule:run consults (skip()/when()).
    $out = [];
    foreach ($app->make(Schedule::class)->events() as $e) {
        $command = is_string($e->command ?? null) ? $e->command : null;
        $out[] = ['name' => $e->description ?? $command ?? get_class($e), 'command' => $command, 'runs' => $e->filtersPass($app)];
    }
    echo json_encode(['events' => $out]) . "\n";
} elseif ($mode === 'untenanted-event') {
    // Fire a real Wellms event with NO tenant active: it must not be recorded or sent.
    $user = (new \App\Models\User())->forceFill(['id' => 7]);
    $course = (new \EscolaLms\Courses\Models\Course())->forceFill(['id' => 3]);
    $listenerError = null;
    try {
        event(new \EscolaLms\Courses\Events\CourseAssigned($user, $course));
    } catch (\Throwable $e) {
        // Other Wellms listeners (notifications/templates) may choke on these deliberately unsaved models; that is an artefact of
        // the artificial event. What matters is what OUR listener did, which the caller checks from the log and the outbox.
        $listenerError = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 120);
    }
    echo json_encode(['fired' => true, 'tenant' => $app->make(\Luminate\Tenancy\Context\CurrentTenant::class)->id(), 'other_listener_error' => $listenerError]) . "\n";
} elseif ($mode === 'dispatch-denied') {
    // Dispatch the real money-moving job as a tenant. The worker must refuse it before it runs.
    \EscolaLms\Cart\Jobs\RenewRecursiveProduct::dispatch();
    echo json_encode(['dispatched' => 'RenewRecursiveProduct', 'tenant' => $app->make(\Luminate\Tenancy\Context\CurrentTenant::class)->id()]) . "\n";
} else {
    fwrite(STDERR, "unknown mode\n");
    exit(2);
}
