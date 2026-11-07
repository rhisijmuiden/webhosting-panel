<?php

namespace App\Http\Controllers\WpImport;

use Illuminate\Http\Request;

final class AdminWpImportController extends WpImportController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
