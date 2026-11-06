<?php

declare(strict_types=1);

namespace Rhis\Migration\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One domain being moved. Status flow:
 * queued -> preparing -> copying -> copied (-> syncing -> copied ...) -> live -> done
 * Any step can end in failed; retry starts again from where it was.
 */
class MigrationItem extends Model
{
    public const ACTIVE = ['preparing', 'copying', 'syncing'];

    protected $fillable = [
        'migration_source_id', 'domain', 'customer_id', 'website_id', 'status', 'options', 'source_data', 'agent_job',
        'job_kind', 'result', 'notes', 'secrets', 'error', 'copied_at', 'synced_at', 'cutover_at',
    ];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'options' => 'array', 'source_data' => 'array', 'result' => 'array', 'notes' => 'array',
            'secrets' => 'encrypted:array', 'copied_at' => 'datetime', 'synced_at' => 'datetime', 'cutover_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MigrationSource::class, 'migration_source_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function canSync(): bool
    {
        return in_array($this->status, ['copied', 'failed'], true) && $this->website_id !== null;
    }

    /** Stored untranslated, so every admin reads it in their own language. */
    public function addNote(string $text, array $params = []): void
    {
        $note = ['text' => $text, 'params' => $params];
        $notes = $this->notes ?? [];
        if (! in_array($note, $notes, true)) {
            $notes[] = $note;
        }
        $this->notes = $notes;
    }

    /** @return list<string> */
    public function noteTexts(): array
    {
        return array_map(fn ($n) => is_array($n) ? __($n['text'], $n['params'] ?? []) : (string) $n, $this->notes ?? []);
    }

    public function statusPill(): string
    {
        return match ($this->status) {
            'copied', 'live', 'done' => 'ok',
            'failed' => 'bad',
            'queued' => 'neutral',
            default => 'warn',
        };
    }
}
