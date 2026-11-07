<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** Last known WordPress hardening state of one install (cache of wphard.status). */
class WpHardeningState extends Model
{
    protected $fillable = ['wordpress_install_id', 'measures', 'on_count', 'total', 'salts_refreshed_at', 'checked_at'];

    protected function casts(): array
    {
        return [
            'measures' => 'array', 'on_count' => 'integer', 'total' => 'integer',
            'salts_refreshed_at' => 'datetime', 'checked_at' => 'datetime',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function isComplete(): bool
    {
        return $this->total > 0 && $this->on_count >= $this->total;
    }
}
