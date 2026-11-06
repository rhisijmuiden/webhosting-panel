<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import of a mailbox at another provider into a local mailbox (imapsync on
 * the server). The source password is stored encrypted only until the run ends.
 */
class MailImport extends Model
{
    public const SECURITY = ['ssl', 'starttls', 'none'];

    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'mailbox_id', 'created_by', 'host', 'port', 'security', 'username', 'password', 'status', 'total', 'done', 'percent',
        'transferred', 'skipped', 'errors', 'bytes', 'error', 'log', 'runs', 'started_at', 'finished_at', 'checked_at',
    ];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'queued', 'done' => 0, 'percent' => 0, 'runs' => 0];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted', 'log' => 'array', 'port' => 'integer', 'total' => 'integer', 'done' => 'integer',
            'percent' => 'integer', 'transferred' => 'integer', 'skipped' => 'integer', 'errors' => 'integer', 'bytes' => 'integer',
            'runs' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'checked_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Pill status for x-ui.pill. */
    public function pill(): string
    {
        return match ($this->status) {
            'done' => 'ok',
            'running' => 'running',
            'queued' => 'pending',
            'partial' => 'warn',
            'failed' => 'failed',
            default => 'off',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'queued' => __('Waiting'),
            'running' => __('Importing'),
            'done' => __('Finished'),
            'partial' => __('Finished with errors'),
            'failed' => __('Failed'),
            'stopped' => __('Stopped'),
            default => $this->status,
        };
    }

    public function source(): string
    {
        return $this->username . ' @ ' . $this->host;
    }

    /** The JSON the import page polls. */
    public function toProgress(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'label' => $this->statusLabel(),
            'active' => $this->isActive(),
            'percent' => $this->percent,
            'done' => $this->done,
            'total' => $this->total,
            'transferred' => $this->transferred,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'error' => $this->error,
            'log' => $this->log ?? [],
        ];
    }
}
