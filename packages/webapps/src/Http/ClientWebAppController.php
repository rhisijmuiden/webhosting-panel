<?php

declare(strict_types=1);

namespace Rhis\WebApps\Http;

use Illuminate\Http\Request;

final class ClientWebAppController extends WebAppController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless(config('webapps.customer_access') && $request->user()->customer_id, 404);

        return (int) $request->user()->customer_id;
    }
}
