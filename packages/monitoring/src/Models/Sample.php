<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

/** One website check. */
class Sample extends Model
{
    public $timestamps = false;

    protected $table = 'monitor_samples';

    protected $fillable = ['website_id', 'checked_at', 'ok', 'code', 'ms'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'ok' => 'boolean'];
    }
}
