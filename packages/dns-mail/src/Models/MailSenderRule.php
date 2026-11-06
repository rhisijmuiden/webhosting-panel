<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sender a domain always accepts (allow) or always sends to Junk (block). */
class MailSenderRule extends Model
{
    public const TYPES = ['allow', 'block'];

    protected $fillable = ['mail_domain_id', 'type', 'value'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function isDomain(): bool
    {
        return !str_contains($this->value, '@');
    }
}
