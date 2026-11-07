<?php

declare(strict_types=1);

namespace Rhis\Cron\Http;

use Illuminate\Http\Request;

final class ClientCronController extends CronController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless(config('cron.customer_access') && $request->user()->customer_id, 404);

        return (int) $request->user()->customer_id;
    }
}
