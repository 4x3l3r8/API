<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Scheduling\ScheduleGuard;
use Luminate\Tenancy\Webhooks\EventMapper;
use PHPUnit\Framework\TestCase;

// ---- stand-ins shaped like the Wellms 1.0.54 classes (accessor names and property names read from its source) ----------------

#[\AllowDynamicProperties]
class FakeModel
{
    public function __construct(private mixed $key = null, array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }

    public function getKey(): mixed
    {
        return $this->key;
    }
}

abstract class FakeCourseEvent
{
    public function __construct(private FakeModel $user, private FakeModel $course)
    {
    }

    public function getUser(): FakeModel
    {
        return $this->user;
    }

    public function getCourse(): FakeModel
    {
        return $this->course;
    }
}

class FakeAssigned extends FakeCourseEvent
{
}

final class FakeUnassigned extends FakeCourseEvent
{
}

final class FakeCourseFinished extends FakeCourseEvent
{
}

final class FakeTopicFinished
{
    public function __construct(private FakeModel $user, private FakeModel $topic)
    {
    }

    public function getUser(): FakeModel
    {
        return $this->user;
    }

    public function getTopic(): FakeModel
    {
        return $this->topic;
    }
}

class FakeQuizEvent
{
    public function __construct(private FakeModel $attempt)
    {
    }

    public function getAttempt(): FakeModel
    {
        return $this->attempt;
    }
}

final class FakeQuizFinished extends FakeQuizEvent
{
}

final class FakeGradeReady extends FakeQuizEvent
{
}

final class EventMapperTest extends TestCase
{
    private function mapper(): EventMapper
    {
        return new EventMapper([
            FakeAssigned::class => 'enrolment.created',
            FakeUnassigned::class => 'enrolment.removed',
            FakeTopicFinished::class => 'topic.completed',
            FakeCourseFinished::class => 'course.completed',
            FakeQuizFinished::class => 'quiz.attempt.finished',
            FakeGradeReady::class => 'quiz.grade.ready',
        ]);
    }

    private function attempt(array $over = []): FakeModel
    {
        $quiz = new FakeModel(5, ['topic' => new FakeModel(8, ['lesson' => new FakeModel(2, ['course_id' => 3])])]);

        return new FakeModel(9, $over + ['user_id' => 7, 'topic_gift_quiz_id' => 5, 'giftQuiz' => $quiz]);
    }

    public function testEnrolmentAndCompletionEventsCarryExactlyTheContractFields(): void
    {
        $u = new FakeModel(7);
        $c = new FakeModel(3);
        foreach ([[new FakeAssigned($u, $c), 'enrolment.created'], [new FakeUnassigned($u, $c), 'enrolment.removed'], [new FakeCourseFinished($u, $c), 'course.completed']] as [$e, $name]) {
            $this->assertSame([$name, ['wellms_user_id' => 7, 'wellms_course_id' => 3]], $this->mapper()->map($e));
        }
    }

    public function testNoGroupIdIsInventedBecauseWellmsNeverEmitsOne(): void
    {
        [, $data] = $this->mapper()->map(new FakeAssigned(new FakeModel(7), new FakeModel(3)));
        $this->assertArrayNotHasKey('wellms_group_id', $data);
    }

    public function testTopicCompletedCarriesTheCourseFromTheLessonAndTheTopicType(): void
    {
        $topic = new FakeModel(11, ['lesson' => new FakeModel(2, ['course_id' => 3]), 'topicable_type' => 'EscolaLms\TopicTypes\Models\TopicContent\RichText']);
        $this->assertSame(
            ['topic.completed', ['wellms_user_id' => 7, 'wellms_course_id' => 3, 'topic_id' => 11, 'topic_type' => 'EscolaLms\TopicTypes\Models\TopicContent\RichText']],
            $this->mapper()->map(new FakeTopicFinished(new FakeModel(7), $topic))
        );
    }

    public function testQuizEventsCarryNoScore(): void
    {
        $finished = $this->mapper()->map(new FakeQuizFinished($this->attempt(['result_score' => 2, 'result_percent' => 100, 'is_passed' => true])));
        $this->assertSame(['quiz.attempt.finished', ['wellms_user_id' => 7, 'wellms_course_id' => 3, 'topic_gift_quiz_id' => 5, 'attempt_id' => 9]], $finished);

        $ready = $this->mapper()->map(new FakeGradeReady($this->attempt(['result_score' => 2])));
        $this->assertSame(['quiz.grade.ready', ['attempt_id' => 9]], $ready);

        $json = json_encode([$finished, $ready]);
        foreach (['score', 'percent', 'passed'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "no '$leak' in a quiz event: grades ride on the pull");
        }
    }

    public function testIdsAreIntegersOnTheWire(): void
    {
        [, $data] = $this->mapper()->map(new FakeAssigned(new FakeModel('7'), new FakeModel(3)));
        $this->assertSame(['wellms_user_id' => 7, 'wellms_course_id' => 3], $data);
        $this->assertStringContainsString('"wellms_user_id":7', json_encode($data));
    }

    public function testAnEventWithAMissingIdIsNotGuessedAt(): void
    {
        $this->assertNull($this->mapper()->map(new FakeAssigned(new FakeModel(null), new FakeModel(3))));
        $this->assertNull($this->mapper()->map(new FakeTopicFinished(new FakeModel(7), new FakeModel(11, ['topicable_type' => 'X']))), 'no course');
        $this->assertNull($this->mapper()->map(new FakeTopicFinished(new FakeModel(7), new FakeModel(11, ['lesson' => new FakeModel(2, ['course_id' => 3])]))), 'no type');
        $this->assertNull($this->mapper()->map(new FakeQuizFinished($this->attempt(['giftQuiz' => null]))), 'quiz without a course');
        $this->assertNull($this->mapper()->map(new FakeQuizFinished(new FakeModel(null, ['user_id' => 7]))));
    }

    public function testAnUnmappedEventIsIgnored(): void
    {
        $this->assertNull($this->mapper()->map(new \stdClass()));
    }

    public function testASubclassOfAMappedEventIsStillMapped(): void
    {
        $sub = new class (new FakeModel(7), new FakeModel(3)) extends FakeAssigned {
        };
        $this->assertSame('enrolment.created', $this->mapper()->map($sub)[0] ?? null);
    }

    // ---- schedule guard: which scheduled events are switched off ---------------------------------------------------------------------------

    public function testTheGuardMatchesJobNamesExactlyAndCommandsByToken(): void
    {
        $patterns = ['EscolaLms\Cart\Jobs\RenewRecursiveProduct', 'EscolaLms\Cart\Jobs\ExpireRecursiveProduct', 'cart:abandoned-event'];

        $this->assertTrue(ScheduleGuard::matches('EscolaLms\Cart\Jobs\RenewRecursiveProduct', null, $patterns));
        $this->assertTrue(ScheduleGuard::matches('EscolaLms\Cart\Jobs\ExpireRecursiveProduct', null, $patterns));
        $this->assertTrue(ScheduleGuard::matches(null, "'/usr/local/bin/php' 'artisan' cart:abandoned-event", $patterns));
        $this->assertTrue(ScheduleGuard::matches(null, '"php" "artisan" cart:abandoned-event', $patterns));

        // look-alikes and unrelated work are NOT switched off
        $this->assertFalse(ScheduleGuard::matches('EscolaLms\Cart\Jobs\RenewRecursiveProductUser', null, $patterns), 'exact name, not a prefix');
        $this->assertFalse(ScheduleGuard::matches('EscolaLms\Courses\Jobs\CheckForDeadlines', null, $patterns));
        $this->assertFalse(ScheduleGuard::matches(null, "'php' 'artisan' cart:abandoned-event-extra", $patterns), 'token match, not substring');
        $this->assertFalse(ScheduleGuard::matches(null, "'php' 'artisan' schedule:run", $patterns));
        $this->assertFalse(ScheduleGuard::matches(null, null, $patterns));
        $this->assertFalse(ScheduleGuard::matches('EscolaLms\Cart\Jobs\RenewRecursiveProduct', null, []), 'nothing configured, nothing disabled');
        $this->assertFalse(ScheduleGuard::matches('anything', 'anything', ['']), 'an empty pattern matches nothing');
    }
}
