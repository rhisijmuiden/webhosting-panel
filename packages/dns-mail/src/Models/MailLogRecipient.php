<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailLogRecipient extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['local' => 'boolean', 'delay' => 'float', 'attempts' => 'integer', 'logged_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailLogMessage::class, 'mail_log_message_id');
    }
}
