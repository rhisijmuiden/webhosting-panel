<?php

declare(strict_types=1);

namespace Rhis\Backups\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer backup or restore job. */
final class ClientBackup extends Model
{
    protected $fillable = [
        'customer_id', 'website_id', 'client_backup_target_id', 'domain', 'action', 'kind', 'trigger', 'status',
        'job_id', 'file', 'size', 'message', 'user', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'size' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ClientBackupTarget::class, 'client_backup_target_id');
    }

    public function label(): string
    {
        return match ($this->action) {
            'backup' => $this->kind === 'incremental' ? __('Incremental backup') : __('Full backup'),
            'restore' => __('Restore from location'),
            'restore_upload' => __('Restore from upload'),
            default => $this->action,
        };
    }
}
