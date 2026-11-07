<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Http;

use App\Models\Website;
use Illuminate\Http\Request;

final class AdminStagingController extends StagingController
{
    protected string $area = 'admin';

    protected function allowed(Request $request, Website $website): bool
    {
        return true;
    }
}
