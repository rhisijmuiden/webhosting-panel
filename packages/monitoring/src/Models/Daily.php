<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

/** Checks per day (day is a plain Y-m-d string in the panel's timezone) of a website (site:<id>) or a status page component (component:<key>). */
class Daily extends Model
{
    public $timestamps = false;

    protected $table = 'monitor_daily';

    protected $fillable = ['key', 'website_id', 'day', 'checks', 'up', 'down_seconds', 'ms_total', 'ms_count'];

    public function uptime(): ?float
    {
        return $this->checks ? round($this->up * 100 / $this->checks, 3) : null;
    }
}
