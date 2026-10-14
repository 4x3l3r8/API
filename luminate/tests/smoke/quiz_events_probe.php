<?php
/**
 * Batch 5 prerequisite probe (dev only). Runs INSIDE the api container as a tenant, via LUMINATE_TENANT:
 *
 *   docker exec -e LUMINATE_TENANT=t-alpha -u www-data luminate-wellms-api-1 php /tmp/quiz_events_probe.php <attempt_id>...
 *
 * For each attempt it runs the real MarkAttemptAsEnded::handle() with the event dispatcher FAKED (so no mail/listener side
 * effects) and reports which quiz events it dispatches, plus end_at before/after. Prints one JSON line per attempt.
 */

use EscolaLms\TopicTypeGift\Events\QuizAttemptFinishedEvent;
use EscolaLms\TopicTypeGift\Events\QuizAttemptJournalGradeReadyEvent;
use EscolaLms\TopicTypeGift\Jobs\MarkAttemptAsEnded;
use EscolaLms\TopicTypeGift\Models\QuizAttempt;
use EscolaLms\TopicTypeGift\Repositories\Contracts\QuizAttemptRepositoryContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;

chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ids = array_map('intval', array_slice($argv, 1));
foreach ($ids as $id) {
    $before = QuizAttempt::find($id);
    if (!$before) {
        echo json_encode(['attempt' => $id, 'error' => 'not visible in this tenant']) . "\n";
        continue;
    }
    $fake = Event::fake();
    $row = [
        'attempt' => $id,
        'is_ended_before' => $before->isEnded(),
        'end_at_before' => (string) $before->end_at,
        'now' => date('Y-m-d H:i:s'),
    ];
    try {
        (new MarkAttemptAsEnded($id))->handle(app(QuizAttemptRepositoryContract::class));
        $row['finished_events'] = $fake->dispatched(QuizAttemptFinishedEvent::class)->count();
        $row['grade_ready_events'] = $fake->dispatched(QuizAttemptJournalGradeReadyEvent::class)->count();
    } catch (\Throwable $e) {
        $row['error'] = get_class($e) . ': ' . $e->getMessage();
    }
    $row['end_at_after'] = (string) (QuizAttempt::find($id)?->end_at);
    echo json_encode($row) . "\n";
}
