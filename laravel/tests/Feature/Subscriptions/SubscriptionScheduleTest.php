<?php

namespace Tests\Feature\Subscriptions;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SubscriptionScheduleTest extends TestCase
{
    public function test_subscription_expiration_command_is_scheduled_hourly(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains(
                (string) $event->command,
                'subscriptions:expire',
            ));

        $this->assertNotNull($event, 'The subscriptions:expire command is not scheduled.');
        $this->assertSame('0 * * * *', $event->expression);
    }
}
