<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/** One database cleanup of a WordPress install. */
class DbCleanup extends Model
{
    protected $table = 'wp_db_cleanups';

    protected $fillable = [
        'wordpress_install_id', 'customer_id', 'user_id', 'status', 'job_id', 'items', 'keep_revisions',
        'result', 'error', 'size_before', 'size_after', 'snapshot', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'result' => 'array', 'keep_revisions' => 'integer',
            'size_before' => 'integer', 'size_after' => 'integer', 'finished_at' => 'datetime',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /** Bytes the database shrank (null while running or unknown). */
    public function freed(): ?int
    {
        return $this->size_before !== null && $this->size_after !== null ? max(0, $this->size_before - $this->size_after) : null;
    }

    /** Rows removed per item, from the agent's answer. */
    public function removed(): array
    {
        return (array) ($this->result['removed'] ?? []);
    }

    public function removedTotal(): int
    {
        return (int) array_sum(array_diff_key($this->removed(), ['optimize' => 0]));
    }
}
