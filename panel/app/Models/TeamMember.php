<?php

namespace App\Models;

use App\Support\TeamAreas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A login of a customer that is not the owner: it only reaches the websites
 * and the areas (files, mail, WordPress, ...) the owner gave it.
 */
class TeamMember extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'invited_by', 'all_websites', 'areas'];

    protected function casts(): array
    {
        return ['all_websites' => 'boolean', 'areas' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'team_member_website');
    }

    public function hasArea(string $area): bool
    {
        return in_array($area, $this->areas ?? [], true);
    }

    /** The areas this member has, as translated labels, in the order of TeamAreas. */
    public function areaLabels(): array
    {
        return collect(TeamAreas::all())->filter(fn ($a, $key) => $this->hasArea($key))
            ->map(fn ($a) => __($a['label']))->values()->all();
    }
}
