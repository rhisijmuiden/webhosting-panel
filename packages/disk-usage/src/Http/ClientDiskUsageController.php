<?php

declare(strict_types=1);

namespace Rhis\DiskUsage\Http;

use App\Models\Website;
use App\Services\Team\TeamAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ClientDiskUsageController extends DiskUsageController
{
    protected string $area = 'client';

    protected function websites(Request $request): Collection
    {
        abort_unless(config('disk-usage.customer'), 404);

        return $request->user()->customer?->websites()->with(['subdomains', 'parent'])->orderBy('domain')->get() ?? collect();
    }

    protected function allowed(Request $request, Website $website): bool
    {
        return config('disk-usage.customer') && $website->customer_id !== null && $website->customer_id === $request->user()?->customer_id;
    }

    /** Team members only see the names of databases and mailboxes when they have that area. */
    protected function sees(string $what): bool
    {
        return app(TeamAccess::class)->allowsArea($what);
    }
}
