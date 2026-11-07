<?php

declare(strict_types=1);

namespace Rhis\DiskUsage\Http;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class AdminDiskUsageController extends DiskUsageController
{
    protected string $area = 'admin';

    protected function websites(Request $request): Collection
    {
        return Website::query()->with(['customer', 'subdomains', 'parent'])->orderBy('domain')->get();
    }

    protected function allowed(Request $request, Website $website): bool
    {
        return true;
    }
}
