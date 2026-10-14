<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Webhooks;

/**
 * Wellms domain event -> contract §F event name + `data`. Duck-typed on the accessors Wellms' events expose, so it can be
 * unit-tested with plain stubs. Returns null (and the caller logs) rather than guessing when a required id is missing.
 *
 * Facts it encodes, read from the Wellms 1.0.54 source:
 *  - CourseAssigned/CourseUnassigned fire only for DIRECT user access. Group-based access fires nothing, so
 *    `wellms_group_id` is never populated from an event (the contract marks it optional).
 *  - Removing a user's access always fires CourseUnassigned AND CourseFinished, so `enrolment.removed` is always
 *    accompanied by `course.completed` (the contract tells the receiver to ignore that one).
 *  - Quiz events carry NO score; grades ride on the reconciliation pull.
 */
final class EventMapper
{
    /** @param array<class-string,string> $events Wellms event class => contract event name */
    public function __construct(private readonly array $events)
    {
    }

    /** @return array{0:string,1:array<string,mixed>}|null */
    public function map(object $event): ?array
    {
        $name = $this->events[$event::class] ?? null;
        if ($name === null) {
            foreach ($this->events as $class => $candidate) {
                if ($event instanceof $class) {
                    $name = $candidate;
                    break;
                }
            }
        }
        if ($name === null) {
            return null;
        }

        $data = match ($name) {
            'enrolment.created', 'enrolment.removed', 'course.completed' => $this->userAndCourse($event),
            'topic.completed' => $this->topicCompleted($event),
            'quiz.attempt.finished' => $this->quizFinished($event),
            'quiz.grade.ready' => $this->gradeReady($event),
            default => null,
        };

        return $data === null ? null : [$name, $data];
    }

    /** @return array<string,int>|null */
    private function userAndCourse(object $event): ?array
    {
        $user = $this->id($event->getUser() ?? null);
        $course = $this->id($event->getCourse() ?? null);

        return $user === null || $course === null ? null : ['wellms_user_id' => $user, 'wellms_course_id' => $course];
    }

    /** @return array<string,mixed>|null */
    private function topicCompleted(object $event): ?array
    {
        $topic = $event->getTopic();
        $user = $this->id($event->getUser() ?? null);
        $topicId = $this->id($topic);
        $lesson = $topic->lesson ?? null;
        $course = $this->intOrNull($lesson->course_id ?? null) ?? $this->id($topic->course ?? null);
        $type = $topic->topicable_type ?? null;

        if ($user === null || $topicId === null || $course === null || !is_string($type) || $type === '') {
            return null;
        }

        // topic_type is Wellms' own `topicable_type` (the model class), exactly as its API exposes it.
        return ['wellms_user_id' => $user, 'wellms_course_id' => $course, 'topic_id' => $topicId, 'topic_type' => $type];
    }

    /** @return array<string,int>|null */
    private function quizFinished(object $event): ?array
    {
        $attempt = $event->getAttempt();
        $attemptId = $this->id($attempt);
        $user = $this->intOrNull($attempt->user_id ?? null);
        $quiz = $this->intOrNull($attempt->topic_gift_quiz_id ?? null);
        $giftQuiz = $attempt->giftQuiz ?? null;
        $topic = $giftQuiz->topic ?? null;
        $lesson = $topic->lesson ?? null;
        $course = $this->intOrNull($lesson->course_id ?? null);

        if ($attemptId === null || $user === null || $quiz === null || $course === null) {
            return null;
        }

        // deliberately no score: the receiver fetches it (and only once the attempt is fully graded) from the attempts API
        return ['wellms_user_id' => $user, 'wellms_course_id' => $course, 'topic_gift_quiz_id' => $quiz, 'attempt_id' => $attemptId];
    }

    /** @return array<string,int>|null */
    private function gradeReady(object $event): ?array
    {
        $attemptId = $this->id($event->getAttempt());

        return $attemptId === null ? null : ['attempt_id' => $attemptId];
    }

    private function id(?object $model): ?int
    {
        return $model === null ? null : $this->intOrNull($model->getKey());
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    }
}
