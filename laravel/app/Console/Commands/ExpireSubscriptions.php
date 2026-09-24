<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark expired active subscriptions as inactive';

    public function handle(): int
    {
        $expiredSubscriptions = Subscription::query()
            ->active()
            ->expiredAt(now())
            ->update(['status' => Subscription::STATUS_INACTIVE]);

        $this->info("Expired subscriptions: {$expiredSubscriptions}");

        return self::SUCCESS;
    }
}
