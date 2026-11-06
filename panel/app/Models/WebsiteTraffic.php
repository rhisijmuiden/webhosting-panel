<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Data traffic (bytes in + out, from Nginx) of one website in one calendar month (UTC). */
class WebsiteTraffic extends Model
{
    protected $table = 'website_traffic';

    protected $fillable = ['website_id', 'month', 'bytes'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
