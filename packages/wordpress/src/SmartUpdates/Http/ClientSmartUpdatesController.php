<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Http;

use Illuminate\Http\Request;

final class ClientSmartUpdatesController extends SmartUpdatesController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless(config('wordpress.customer_toolkit'), 404);

        return (int) $request->user()->customer_id;
    }

    /** Customers choose the mode of their own sites, unless the admin turned that off. */
    protected function mayChange(): bool
    {
        return (bool) $this->options->get('customer_policy');
    }
}
