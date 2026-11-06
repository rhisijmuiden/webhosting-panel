<?php

declare(strict_types=1);

namespace Rhis\DomainAliases\Http;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Admins manage the aliases of every website; package limits are not enforced for them. */
final class AdminAliasController extends AliasController
{
    protected string $area = 'admin';

    protected function websites(Request $request): Builder
    {
        return Website::query();
    }

    protected function enforceLimit(): bool
    {
        return false;
    }
}
