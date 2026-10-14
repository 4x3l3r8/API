<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contract v5 §F "Final vs provisional": exposes `is_fully_graded` on the admin quiz-attempts LIST (and detail) items so the
 * reconciliation pull can ingest only fully graded attempts without an N+1 call per attempt.
 *
 * Wellms builds those items in a vendor JsonResource we cannot change without patching core, so this adds one key to the JSON
 * response. It mirrors the model's isFullyGraded() exactly (no answer with a null graded_at; an attempt with no answers counts
 * as fully graded) but answers it for the whole page with ONE query. It only ever adds a key; it never removes or changes one.
 *
 * NOTE for the contract: for an attempt that is still OPEN this can read true (every answer so far is auto-graded) while its
 * result_score is null. "Final" is therefore `is_ended && is_fully_graded`.
 */
final class AddFullyGraded
{
    private const PATH = '#^api/admin/quiz-attempts(/\d+)?$#';

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$request->isMethod('GET') || preg_match(self::PATH, $request->path()) !== 1
            || !$response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        $payload = $response->getData(true);
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return $response;
        }

        $single = array_key_exists('id', $data);
        $items = $single ? [$data] : $data;
        $ids = array_values(array_filter(array_map(static fn ($i) => is_array($i) ? ($i['id'] ?? null) : null, $items), 'is_int'));
        if ($ids === []) {
            return $response;
        }

        $ungraded = array_flip(
            DB::table('topic_gift_attempt_answers')
                ->whereIn('topic_gift_quiz_attempt_id', $ids)->whereNull('graded_at')
                ->distinct()->pluck('topic_gift_quiz_attempt_id')->map(fn ($v) => (int) $v)->all()
        );
        $mark = static fn (array $i): array => $i + ['is_fully_graded' => !isset($ungraded[$i['id'] ?? 0])];

        $payload['data'] = $single ? $mark($data) : array_map(static fn ($i) => is_array($i) && isset($i['id']) ? $mark($i) : $i, $data);
        $response->setData($payload);

        return $response;
    }
}
