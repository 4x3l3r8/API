<?php

namespace Luminate\Webhooks;

use EscolaLms\Courses\Events\CourseAssigned;
use EscolaLms\Courses\Events\CourseFinished;
use EscolaLms\Courses\Events\CourseUnassigned;
use EscolaLms\Courses\Events\TopicFinished;
use EscolaLms\TopicTypeGift\Events\QuizAttemptFinishedEvent;
use EscolaLms\TopicTypeGift\Events\QuizAttemptJournalGradeReadyEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Luminate\Webhooks\Listeners\SendWebhook;

class LuminateWebhooksServiceProvider extends ServiceProvider
{
    public const EVENTS = [
        CourseAssigned::class => 'enrolment.created',
        CourseUnassigned::class => 'enrolment.removed',
        TopicFinished::class => 'topic.completed',
        CourseFinished::class => 'course.completed',
        QuizAttemptFinishedEvent::class => 'quiz.attempt.finished',
        // fires when a graded attempt of a quiz flagged counts_to_grade is final (auto-scored, or last essay graded)
        QuizAttemptJournalGradeReadyEvent::class => 'quiz.grade.ready',
    ];

    public function register(): void
    {
        $this->app->bind('luminate.webhooks.config', fn () => [
            'url' => env('LUMINATE_WEBHOOK_URL'),
            'secret' => env('LUMINATE_WEBHOOK_SECRET', ''),
        ]);
    }

    public function boot(): void
    {
        foreach (array_keys(self::EVENTS) as $eventClass) {
            Event::listen($eventClass, SendWebhook::class);
        }
    }
}
