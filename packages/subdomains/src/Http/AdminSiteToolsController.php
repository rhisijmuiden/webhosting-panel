<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class AdminSiteToolsController extends SiteToolsController
{
    protected string $area = 'admin';

    protected function allowed(Request $request, Website $website): bool
    {
        return true;
    }
}
