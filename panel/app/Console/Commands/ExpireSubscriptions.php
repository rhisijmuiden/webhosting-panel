<?php

namespace App\Console\Commands;

use App\Services\SubscriptionManager;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'whp:subscriptions:expire';

    protected $description = 'Suspend the subscriptions whose expiry date has passed';

    public function handle(SubscriptionManager $subscriptions): int
    {
        $count = $subscriptions->expire();
        $this->info(trans_choice(':count subscription suspended.|:count subscriptions suspended.', $count));

        return self::SUCCESS;
    }
}
