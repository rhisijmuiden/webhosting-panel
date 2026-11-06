<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CrmJob extends Model
{
    use HasUlids;

    protected $table = 'crm_jobs';

    protected $fillable = ['type', 'status', 'progress', 'step', 'error', 'subscription_id', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime', 'progress' => 'integer'];
    }

    public function step(string $step, int $progress): void
    {
        $this->update(['status' => 'running', 'step' => $step, 'progress' => $progress]);
    }

    public function succeed(): void
    {
        $this->update(['status' => 'succeeded', 'progress' => 100, 'step' => null, 'finished_at' => now()]);
    }

    public function fail(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => mb_substr($error, 0, 2000), 'finished_at' => now()]);
    }
}
