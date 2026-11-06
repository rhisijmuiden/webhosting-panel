<?php

declare(strict_types=1);

namespace Rhis\Git\Http;

use Illuminate\Http\Request;

final class ClientGitController extends GitController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless(config('git.customer_access') && $request->user()->customer_id, 404);

        return (int) $request->user()->customer_id;
    }
}
