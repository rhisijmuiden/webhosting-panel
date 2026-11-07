<?php

namespace App\Http\Controllers\WpImport;

use Illuminate\Http\Request;

final class ClientWpImportController extends WpImportController
{
    protected string $area = 'client';

    protected function customerId(Request $request): ?int
    {
        abort_unless(config('wp-import.customer_access') && $request->user()->customer_id, 404);

        return (int) $request->user()->customer_id;
    }
}
