<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Smoke test for Console\Kernel::schedule(): every ops-critical command must
 * stay registered. Nothing else asserts this — a command accidentally
 * dropped from the schedule fails silently in prod until someone notices
 * imports (or log pruning, or restock alerts) stopped happening.
 */
class ScheduleRegistrationTest extends TestCase
{
    public function test_all_expected_commands_are_scheduled(): void
    {
        $events = app(Schedule::class)->events();
        $commands = implode("\n", array_map(fn ($e) => (string) $e->command, $events));

        $expected = [
            'roasters:import-all',
            'alerts:restock',
            'roasters:scrape-addresses',
            'reports:weekly-digest',
            'reports:daily-ops',
            'coffees:purge-non-coffee',
            'roasters:auto-deactivate-dead',
            'roasters:retry-inactive',
            'logs:prune',
        ];

        foreach ($expected as $command) {
            $this->assertStringContainsString($command, $commands, "{$command} missing from the schedule");
        }
    }

    public function test_scheduler_heartbeat_callback_is_registered(): void
    {
        $heartbeats = array_filter(
            app(Schedule::class)->events(),
            fn ($e) => $e instanceof CallbackEvent && str_contains((string) $e->description, 'scheduler-heartbeat')
        );

        $this->assertCount(1, $heartbeats, 'the scheduler.tick heartbeat keeps /up honest — it must stay scheduled');
    }
}
