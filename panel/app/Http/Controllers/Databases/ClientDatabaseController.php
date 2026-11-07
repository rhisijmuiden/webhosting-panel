<?php

namespace App\Http\Controllers\Databases;

use Illuminate\Http\Request;

final class ClientDatabaseController extends DatabaseController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless($request->user()->customer_id, 404);

        return (int) $request->user()->customer_id;
    }
}
