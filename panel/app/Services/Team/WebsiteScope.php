<?php

namespace App\Services\Team;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides websites (and rows that belong to a website) a team member was not
 * given. It only filters while a limited team member is logged in, so admin
 * pages, jobs and the CLI see everything.
 */
class WebsiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ids = app(TeamAccess::class)->scopedWebsiteIds();
        if ($ids === null) {
            return;
        }
        $column = $model instanceof Website ? $model->getQualifiedKeyName() : $model->qualifyColumn('website_id');
        $builder->whereIn($column, $ids ?: [0]);
    }
}
