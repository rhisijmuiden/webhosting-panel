<?php

declare(strict_types=1);

namespace Rhis\WebApps\Http;

use Illuminate\Http\Request;

final class AdminWebAppController extends WebAppController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
