<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ResourceLimits;
use Illuminate\Console\Command;

class ApplyLimits extends Command
{
    protected $signature = 'whp:limits:apply {subscription? : Subscription id (default: all subscriptions)} {--customer= : Only the subscriptions of this customer id}';

    protected $description = 'Put the resource limits and PHP settings of the packages in place on the server';

    public function handle(ResourceLimits $limits): int
    {
        $subscriptions = Subscription::with('package', 'website')
            ->when($this->argument('subscription'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('customer'), fn ($q, $id) => $q->ownedBy((int) $id))
            ->orderBy('id')->get();
        $failed = 0;
        foreach ($subscriptions as $subscription) {
            $errors = $limits->apply($subscription);
            $this->line(($errors ? '<error>✗</error> ' : '<info>✓</info> ').$subscription->label());
            foreach ($errors as $error) {
                $this->line('    '.$error);
            }
            $failed += $errors ? 1 : 0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
