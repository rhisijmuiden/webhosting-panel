<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One message in the mail log (see Services\MailLogCollector). */
class MailLogMessage extends Model
{
    public const ORIGINS = ['authenticated', 'website', 'system', 'inbound', 'bounce', 'unknown'];

    public const DIRECTIONS = ['outgoing', 'incoming', 'internal', 'unknown'];

    public const STATUSES = ['queued', 'sent', 'deferred', 'bounced', 'rejected', 'expired', 'mixed'];

    protected $guarded = ['id'];

    protected $attributes = ['origin' => 'unknown', 'direction' => 'unknown', 'status' => 'queued', 'recipient_count' => 0];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime', 'finished_at' => 'datetime', 'size' => 'integer', 'recipient_count' => 'integer',
            'spam_score' => 'float', 'spam_symbols' => 'array',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailLogRecipient::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'sent' => 'ok',
            'deferred', 'queued', 'mixed' => 'warn',
            'bounced', 'rejected', 'expired' => 'bad',
            default => 'neutral',
        };
    }
}
