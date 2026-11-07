<?php

declare(strict_types=1);

namespace Rhis\SiteLogs\Http;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Admins: the logs of every website. */
final class AdminLogController extends LogController
{
    protected string $area = 'admin';

    protected function authorizeSite(Request $request, Website $website): void {}

    protected function websites(Request $request): Collection
    {
        $q = trim((string) $request->query('q', ''));

        return Website::query()->with('customer')
            ->when($q !== '', fn ($query) => $query->where('domain', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('domain')->limit(500)->get();
    }
}
