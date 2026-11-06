<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\ResourceLimits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Puts the limits of a subscription's package in place on the server. */
class ApplySubscriptionLimits implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Subscription $subscription) {}

    public function handle(ResourceLimits $limits): void
    {
        $limits->apply($this->subscription->fresh() ?? $this->subscription);
    }
}
