<?php

declare(strict_types=1);

namespace Rhis\WebRules\Http;

use App\Models\Website;
use Illuminate\Http\Request;

/** Customers: the rules of their own websites (when the admin allows it). */
final class ClientWebRulesController extends WebRulesController
{
    protected string $area = 'client';

    protected function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
        abort_unless((bool) config('web-rules.customer_access'), 403);
    }
}
