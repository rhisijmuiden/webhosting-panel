<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Out-of-office reply of one mailbox. The agent turns it into a Sieve vacation script. */
class MailAutoreply extends Model
{
    protected $fillable = ['mailbox_id', 'enabled', 'subject', 'body', 'starts_on', 'ends_on', 'interval_days'];

    protected $attributes = ['enabled' => false, 'interval_days' => 1];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date', 'interval_days' => 'integer'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** off | scheduled | active | ended */
    public function state(?CarbonInterface $today = null): string
    {
        $today = ($today ?? now())->copy()->startOfDay();
        if (!$this->enabled) {
            return 'off';
        }
        if ($this->ends_on !== null && $this->ends_on->lt($today)) {
            return 'ended';
        }
        if ($this->starts_on !== null && $this->starts_on->gt($today)) {
            return 'scheduled';
        }

        return 'active';
    }
}
