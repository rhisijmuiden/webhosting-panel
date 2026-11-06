<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostingPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'crm_id', 'description', 'disk_mb', 'max_websites', 'max_mailboxes',
        'max_databases', 'max_cronjobs', 'allow_ssh', 'is_active',
    ];

    protected function casts(): array
    {
        return ['allow_ssh' => 'boolean', 'is_active' => 'boolean'];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
