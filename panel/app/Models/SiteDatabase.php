<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A MariaDB database that belongs to a website (one name across the whole server). */
class SiteDatabase extends Model
{
    /** Names a website may never get: MariaDB's own and the panel's. */
    public const RESERVED = ['mysql', 'information_schema', 'performance_schema', 'sys', 'whp_panel', 'snappymail', 'phpmyadmin'];

    protected $fillable = ['website_id', 'name', 'users', 'origin'];

    protected function casts(): array
    {
        return ['users' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
