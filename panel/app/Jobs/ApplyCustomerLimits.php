<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\ResourceLimits;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Puts the limits of the customer's package in place on the server. */
class ApplyCustomerLimits implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public Customer $customer) {}

    public function handle(ResourceLimits $limits): void
    {
        $limits->apply($this->customer->fresh() ?? $this->customer);
    }
}
