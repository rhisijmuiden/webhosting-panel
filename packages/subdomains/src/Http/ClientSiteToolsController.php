<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class ClientSiteToolsController extends SiteToolsController
{
    protected string $area = 'client';

    protected function allowed(Request $request, Website $website): bool
    {
        return $website->customer_id !== null && $website->customer_id === $request->user()?->customer_id;
    }

    protected function canManageSubdomains(): bool
    {
        return (bool) config('subdomains.customer_manage');
    }

    protected function canClone(): bool
    {
        return (bool) config('subdomains.customer_clone');
    }
}
