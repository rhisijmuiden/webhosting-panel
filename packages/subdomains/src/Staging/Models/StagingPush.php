<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One push of a staging copy to live, or one restore of the backup made before a push. */
final class StagingPush extends Model
{
    protected $table = 'wp_staging_pushes';

    protected $fillable = [
        'staging_id', 'action', 'status', 'job_id', 'options', 'result', 'backup', 'rolled_back', 'error', 'user_id',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'result' => 'array', 'rolled_back' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function staging(): BelongsTo
    {
        return $this->belongsTo(Staging::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function busy(): bool
    {
        return $this->status === 'running';
    }
}
