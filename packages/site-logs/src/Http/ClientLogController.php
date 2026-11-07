<?php

declare(strict_types=1);

namespace Rhis\SiteLogs\Http;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Customers: the logs of their own websites (team members: the websites they were given). */
final class ClientLogController extends LogController
{
    protected string $area = 'client';

    protected function authorizeSite(Request $request, Website $website): void
    {
        abort_unless(config('site-logs.customer_access'), 404);
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
    }

    protected function websites(Request $request): Collection
    {
        abort_unless(config('site-logs.customer_access'), 404);

        return Website::query()->where('customer_id', $request->user()->customer_id ?? -1)->orderBy('domain')->get();
    }
}
