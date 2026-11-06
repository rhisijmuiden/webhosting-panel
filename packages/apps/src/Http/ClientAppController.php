<?php

declare(strict_types=1);

namespace Rhis\Apps\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Rhis\Apps\AppInstaller;
use Rhis\Apps\Models\AppInstallation;

/** Customers: install apps into their own websites, within the databases of their package. */
final class ClientAppController extends AppController
{
    protected string $area = 'client';

    public function __construct(AppInstaller $installer)
    {
        parent::__construct($installer);
        abort_unless(config('apps.customer_install'), 404);
    }

    protected function websites(Request $request): Builder
    {
        return Website::query()->where('customer_id', $request->user()->customer_id);
    }

    protected function installations(Request $request): Builder
    {
        return AppInstallation::query()->where('customer_id', $request->user()->customer_id);
    }

    protected function databasesLeft(Request $request): ?int
    {
        $customer = $request->user()->customer;

        return $customer ? $this->installer->databasesLeft($customer) : 0;
    }

    protected function hasDatabaseRoom(Website $website): bool
    {
        $left = $this->installer->databasesLeftFor($website);

        return $left === null || $left > 0;
    }
}
