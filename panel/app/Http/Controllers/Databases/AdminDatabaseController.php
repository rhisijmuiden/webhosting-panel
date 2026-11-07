<?php

namespace App\Http\Controllers\Databases;

use Illuminate\Http\Request;

final class AdminDatabaseController extends DatabaseController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
