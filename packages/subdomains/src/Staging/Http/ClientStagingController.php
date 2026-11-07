<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class ClientStagingController extends StagingController
{
    protected string $area = 'client';

    protected function allowed(Request $request, Website $website): bool
    {
        // Team members only see the websites they were given (Website global scope).
        return $website->customer_id !== null && $website->customer_id === $request->user()?->customer_id
            && Website::whereKey($website->id)->exists();
    }

    protected function enabled(): bool
    {
        return (bool) config('subdomains.customer_staging');
    }
}
