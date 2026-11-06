<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\ResourceLimits;
use Illuminate\Console\Command;

class ApplyLimits extends Command
{
    protected $signature = 'whp:limits:apply {customer? : Customer id (default: all customers with websites)}';

    protected $description = 'Put the resource limits and PHP settings of the packages in place on the server';

    public function handle(ResourceLimits $limits): int
    {
        $customers = Customer::with('package')->whereHas('websites')
            ->when($this->argument('customer'), fn ($q, $id) => $q->whereKey($id))->get();
        $failed = 0;
        foreach ($customers as $customer) {
            $errors = $limits->apply($customer);
            $this->line(($errors ? '<error>✗</error> ' : '<info>✓</info> ').$customer->displayName());
            foreach ($errors as $error) {
                $this->line('    '.$error);
            }
            $failed += $errors ? 1 : 0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
