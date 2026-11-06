<?php

declare(strict_types=1);

namespace Rhis\Git\Http;

use Illuminate\Http\Request;

final class AdminGitController extends GitController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
