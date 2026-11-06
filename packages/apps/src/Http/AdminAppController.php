<?php

declare(strict_types=1);

namespace Rhis\Apps\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rhis\Apps\Models\AppInstallation;

/** Admin: install apps into any website and see every installation. */
final class AdminAppController extends AppController
{
    protected string $area = 'admin';

    protected function websites(Request $request): Builder
    {
        return Website::query();
    }

    protected function installations(Request $request): Builder
    {
        return AppInstallation::query();
    }
}
