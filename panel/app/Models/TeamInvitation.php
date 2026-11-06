<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An open invitation to join a customer's team. Only the sha256 of the token is stored. */
class TeamInvitation extends Model
{
    protected $fillable = [
        'customer_id', 'invited_by', 'email', 'name', 'locale', 'token_hash',
        'all_websites', 'website_ids', 'areas', 'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'all_websites' => 'boolean',
            'website_ids' => 'array',
            'areas' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }
}
