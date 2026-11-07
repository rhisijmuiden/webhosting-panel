<?php

declare(strict_types=1);

namespace Rhis\Cron\Http;

use Illuminate\Http\Request;

final class AdminCronController extends CronController
{
    protected string $area = 'admin';

    protected function customerId(Request $request): ?int
    {
        return null;
    }
}
