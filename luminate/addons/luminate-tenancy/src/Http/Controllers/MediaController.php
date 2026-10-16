<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Http\Controllers;

use EscolaLms\Courses\Models\Course;
use EscolaLms\Courses\Models\Topic;
use EscolaLms\TopicTypes\Models\TopicContent\AbstractTopicFileContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Luminate\Tenancy\Media\ByteRange;
use Luminate\Tenancy\Media\MediaObject;
use Luminate\Tenancy\Media\MediaStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/courses/{course_id}/topics/{topic_id}/media - the bytes of a topic's file (PDF, video, audio, image), streamed from the TENANT's
 * storage, for the Luminate proxy (contract D.6 fallback: the browser never reaches the bucket).
 *
 *  - Authentication is the ordinary bearer token (`auth:api`); the tenant is the verified X-Tenant-Context (global middleware), never the URL.
 *  - Authorisation is Wellms' own course rule (CoursesPolicy::attend: admin, owner/attend permission, or a published course the user has):
 *    403 without access. The course is checked BEFORE the topic, so a user without access cannot probe topic ids.
 *  - The topic must belong to THAT course, and have a file: otherwise 404.
 *  - Range / If-Range (206, 416), If-None-Match (304), HEAD. Never buffered, never a redirect.
 *  - `Cache-Control: private, no-cache` + `Vary`: a response is per user and per tenant, and is revalidated, not served from a shared cache.
 */
final class MediaController
{
    public function __construct(private readonly MediaStore $store)
    {
    }

    public function show(Request $request, int $course_id, int $topic_id): Response|StreamedResponse|JsonResponse
    {
        $user = $request->user('api');
        $course = Course::query()->find($course_id);
        if ($course === null) {
            return $this->refuse(404, 'media_not_found', 'Not found.');
        }
        if ($user === null || !Gate::forUser($user)->allows('attend', $course)) {
            return $this->refuse(403, 'media_forbidden', 'You do not have access to this course.');
        }
        $topic = Topic::query()->whereKey($topic_id)->whereHas('lesson', fn ($q) => $q->where('course_id', $course->getKey()))->first();
        $content = $topic?->topicable;
        if (!$content instanceof AbstractTopicFileContent || !is_string($content->value) || $content->value === '') {
            return $this->refuse(404, 'media_not_found', 'Not found.');
        }

        try {
            $object = $this->store->open($content->value);
        } catch (\RuntimeException) {
            return $this->refuse(404, 'media_not_found', 'Not found.'); // already logged critical by the store; say nothing about why
        }
        if ($object === null) {
            return $this->refuse(404, 'media_not_found', 'Not found.');
        }

        return $this->deliver($request, $object);
    }

    private function deliver(Request $request, MediaObject $object): Response|StreamedResponse
    {
        $headers = [
            'Accept-Ranges' => 'bytes',
            'ETag' => $object->etag,
            'Content-Type' => $object->contentType,
            'Content-Disposition' => 'inline; filename="' . $object->filename . '"',
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization, X-Tenant-Context',
            'X-Content-Type-Options' => 'nosniff',
            // a stored file is untrusted content: if a browser ever renders it, it renders in a sandbox with no scripts
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ];
        if ($object->lastModified !== null) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $object->lastModified) . ' GMT';
        }

        if ($this->notModified($request, $object->etag)) {
            return new Response('', 304, $headers);
        }

        $r = ByteRange::resolve($request->headers->get('Range'), $request->headers->get('If-Range'), $object->size, $object->etag, $object->lastModified);
        if ($r['status'] === ByteRange::UNSATISFIABLE) {
            return new Response('', 416, $headers + ['Content-Range' => 'bytes */' . $object->size]);
        }
        $length = $object->size === 0 ? 0 : $r['end'] - $r['start'] + 1;
        $headers['Content-Length'] = (string) $length;
        if ($r['status'] === ByteRange::PARTIAL) {
            $headers['Content-Range'] = "bytes {$r['start']}-{$r['end']}/{$object->size}";
        }

        if ($request->isMethod('HEAD') || $length === 0) {
            return new Response('', $r['status'], $headers);
        }

        $next = $object->chunks($r['start'], $r['end']);

        return new StreamedResponse(function () use ($next): void {
            @set_time_limit(0);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            while (($data = $next()) !== null) {
                echo $data;
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }, $r['status'], $headers);
    }

    private function notModified(Request $request, string $etag): bool
    {
        $inm = $request->headers->get('If-None-Match');
        if ($inm === null || trim($inm) === '') {
            return false;
        }
        foreach (explode(',', $inm) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('#^W/#', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        if ($status === 403) {
            Log::info('media denied', ['code' => $code]);
        }

        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
