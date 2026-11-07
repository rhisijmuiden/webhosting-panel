<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra MariaDB user of a website, with rights on one or more of its
 * databases (the main user of a database is kept on SiteDatabase::users).
 */
class DatabaseUser extends Model
{
    protected $table = 'site_database_users';

    protected $fillable = ['website_id', 'name', 'databases', 'readonly'];

    protected function casts(): array
    {
        return ['databases' => 'array', 'readonly' => 'boolean'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
