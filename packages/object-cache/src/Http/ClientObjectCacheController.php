<?php

declare(strict_types=1);

namespace Rhis\ObjectCache\Http;

use App\Models\Website;
use Illuminate\Http\Request;

/** Customers: the object cache of their own websites, when their package has one. */
final class ClientObjectCacheController extends ObjectCacheController
{
    protected function authorizeWebsite(Request $request, Website $website): void
    {
        abort_unless(config('object-cache.customer_access'), 403);
        abort_unless($request->user()?->customer_id && (int) $website->customer_id === (int) $request->user()->customer_id, 404);
        abort_if($website->status === 'removing', 404);
    }
}
