<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAlias extends Model
{
    protected $fillable = ['mail_domain_id', 'local_part', 'targets'];

    protected function casts(): array
    {
        return ['targets' => 'array'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function address(): string
    {
        return $this->local_part . '@' . $this->domain->domain;
    }

    public function isCatchAll(): bool
    {
        return $this->local_part === '';
    }
}
