<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** The outcome of a bulk run on one install. */
class BulkRunItem extends Model
{
    protected $table = 'wp_bulk_run_items';

    protected $fillable = ['run_id', 'wordpress_install_id', 'website_id', 'label', 'customer', 'position', 'status',
        'message', 'details', 'agent_job', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BulkRun::class, 'run_id');
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function isDone(): bool
    {
        return ! in_array($this->status, ['pending', 'running'], true);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('Waiting'),
            'running' => __('Busy'),
            'succeeded' => __('Done'),
            'attention' => __('Needs attention'),
            'failed' => __('Failed'),
            'skipped' => __('Skipped'),
            'cancelled' => __('Cancelled'),
            default => $status,
        };
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id, 'label' => $this->label, 'customer' => $this->customer, 'status' => $this->status,
            'status_label' => self::statusLabel($this->status), 'message' => $this->message,
            'details' => $this->details ?? [], 'install' => $this->wordpress_install_id,
            'install_url' => $this->wordpress_install_id ? route('admin.wordpress.show', $this->wordpress_install_id) : null,
            'seconds' => $this->started_at ? (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true) : null,
        ];
    }
}
