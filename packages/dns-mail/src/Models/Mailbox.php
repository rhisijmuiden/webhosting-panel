<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    protected $fillable = ['mail_domain_id', 'local_part', 'display_name', 'password_hash', 'quota_mb', 'enabled', 'send_as', 'used_kb'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'send_as' => 'array', 'quota_mb' => 'integer', 'used_kb' => 'integer'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function address(): string
    {
        return $this->local_part . '@' . $this->domain->domain;
    }

    /** Dovecot reads PHP's Argon2id hashes with the {ARGON2ID} scheme. */
    public static function hashPassword(string $password): string
    {
        return '{ARGON2ID}' . password_hash($password, PASSWORD_ARGON2ID);
    }

    public function usedPercent(): ?int
    {
        if ($this->used_kb === null || $this->quota_mb === 0) {
            return null;
        }

        return (int) min(100, round($this->used_kb / ($this->quota_mb * 1024) * 100));
    }
}
