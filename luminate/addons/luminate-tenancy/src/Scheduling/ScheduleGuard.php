<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Switches off scheduled work that Luminate owns. Contract §H: "Wellms must never autonomously move money". Wellms'
 * cart module schedules RenewRecursiveProduct hourly, which dispatches a job per subscription that creates an order and
 * calls the payment gateway; ExpireRecursiveProduct and cart:abandoned-event are the rest of its commerce automation.
 *
 * Done with Laravel's own skip(): the events stay registered (so `schedule:list` still shows them and an upgrade that
 * renames one is visible) but never run. No Wellms file is touched.
 */
final class ScheduleGuard
{
    /**
     * @param list<string> $patterns an event is disabled if its name equals a pattern (jobs) or its command contains it
     * @return list<string> descriptions of the events that were disabled
     */
    public function disable(Schedule $schedule, array $patterns): array
    {
        $disabled = [];
        foreach ($schedule->events() as $event) {
            if (self::matches($event->description, $this->commandOf($event), $patterns)) {
                $event->skip(true);
                $disabled[] = $event->description ?? (string) $this->commandOf($event);
            }
        }

        return $disabled;
    }

    /** @param list<string> $patterns */
    public static function matches(?string $description, ?string $command, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($p === '') {
                continue;
            }
            if ($description !== null && $description === $p) {
                return true;
            }
            // artisan commands are scheduled as "'php' 'artisan' cart:abandoned-event": match the command token
            if ($command !== null && preg_match('/(^|[\s\'"])' . preg_quote($p, '/') . '([\s\'"]|$)/', $command) === 1) {
                return true;
            }
        }

        return false;
    }

    private function commandOf(Event $event): ?string
    {
        return is_string($event->command ?? null) ? $event->command : null;
    }
}
