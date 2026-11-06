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
        'job_kind', 'result', 'notes', 'log', 'secrets', 'error', 'started_at', 'copied_at', 'synced_at', 'cutover_at',
    ];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'options' => 'array', 'source_data' => 'array', 'result' => 'array', 'notes' => 'array', 'log' => 'array', 'started_at' => 'datetime',
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

    public const LOG_LIMIT = 400;

    /**
     * Add a line to the migration log. Like notes it is stored untranslated, so
     * every admin reads it in their own language. Not saved by itself.
     */
    public function log(string $text, array $params = [], string $level = 'info'): static
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'level' => $level, 'text' => $text, 'params' => $params];
        $this->log = array_slice($log, -self::LOG_LIMIT);

        return $this;
    }

    /** Copy what the agent printed for a finished copy job, so it stays readable afterwards. */
    public function logAgent(array $lines, ?float $finishedAt = null): static
    {
        if ($lines === []) {
            return $this;
        }
        $at = ($finishedAt ? \Illuminate\Support\Carbon::createFromTimestamp($finishedAt) : now())->toIso8601String();
        $log = $this->log ?? [];
        foreach (array_slice($lines, -200) as $line) {
            $line = (string) $line;
            $log[] = ['at' => $at, 'level' => self::agentLevel($line), 'agent' => true, 'text' => $line, 'params' => []];
        }
        $this->log = array_slice($log, -self::LOG_LIMIT);

        return $this;
    }

    public static function agentLevel(string $line): string
    {
        return match (true) {
            str_contains($line, ': failed') || str_starts_with($line, 'Traceback') => 'error',
            str_contains($line, ': done') => 'ok',
            default => 'info',
        };
    }

    /** @return list<array{at: string, level: string, text: string, agent: bool}> */
    public function logLines(): array
    {
        return array_map(fn (array $l) => [
            'at' => (string) ($l['at'] ?? ''),
            'level' => (string) ($l['level'] ?? 'info'),
            'agent' => (bool) ($l['agent'] ?? false),
            'text' => ($l['agent'] ?? false) ? (string) $l['text'] : __((string) $l['text'], $l['params'] ?? []),
        ], $this->log ?? []);
    }

    /**
     * The stored log plus the lines of a copy job that is still running, with a
     * display time, ready for the log viewer.
     *
     * @return list<array{at: string, level: string, text: string, agent: bool, time: string}>
     */
    public function logView(?array $job = null): array
    {
        $log = $this->logLines();
        $live = $job !== null && in_array($this->status, ['copying', 'syncing'], true) ? ($job['log'] ?? []) : [];
        foreach ($live as $line) {
            $log[] = ['at' => '', 'level' => self::agentLevel((string) $line), 'agent' => true, 'text' => (string) $line];
        }

        return array_map(function (array $l) {
            $at = $l['at'] !== '' ? \Illuminate\Support\Carbon::parse($l['at'])->timezone(config('app.timezone')) : null;

            return $l + ['time' => $at?->format('H:i:s') ?? '', 'date' => $at?->format('d-m-Y H:i:s') ?? ''];
        }, $log);
    }

    /**
     * Where the migration is: stage 1-5 (prepare, copy, check, DNS, finished),
     * the percentage of the whole and a short label of the current step.
     * $job is the running agent job (job.status), if any.
     *
     * @return array{stage: int, percent: int, step: string}
     */
    public function progress(?array $job = null): array
    {
        $stage = match ($this->status) {
            'queued', 'preparing' => 1,
            'copying' => 2,
            'copied', 'syncing' => 3,
            'failed' => $this->copied_at ? 3 : ($this->agent_job ? 2 : 1),
            'live' => 4,
            'done' => 5,
            default => 1,
        };
        $base = [1 => 0, 2 => 15, 3 => 75, 4 => 90, 5 => 100][$stage];
        $step = match ($this->status) {
            'queued' => __('Waiting for a free slot'),
            'preparing' => __('Creating customer, website and mailboxes'),
            'copied' => __('Copied, ready to check and switch DNS'),
            'live' => __('DNS switched to this server'),
            'done' => __('Finished'),
            'failed' => __('Stopped with an error'),
            default => __('Copying'),
        };
        if ($job !== null && in_array($this->status, ['copying', 'syncing'], true)) {
            [$done, $total, $current] = self::transferSteps($job['log'] ?? [], $this->transferTotal());
            $span = $this->status === 'copying' ? 60 : 15;
            $base += (int) floor($span * $done / max(1, $total));
            if ($current !== null) {
                $step = $current;
            }
        }

        return ['stage' => $stage, 'percent' => min(100, $base), 'step' => $step];
    }

    /** Number of parts the agent copies for this item: files, each database, each mailbox, certificate. */
    public function transferTotal(): int
    {
        $o = $this->options ?? [];
        $d = $this->source_data ?? [];
        $kind = $this->job_kind ?? 'initial';
        $n = 0;
        if ($kind !== 'late_mail') {
            $n += (($o['files'] ?? false) && ! empty($d['web'])) ? 1 : 0;
            $n += ($o['databases'] ?? false) ? count(array_filter($d['databases'] ?? [], fn ($db) => ($db['type'] ?? 'mysql') === 'mysql' && ($db['server'] ?? 'local') === 'local')) : 0;
            $n += ($kind === 'initial' && ($o['ssl'] ?? false)) ? 1 : 0;
        }
        $n += ($o['mail'] ?? false) ? count($d['mailboxes'] ?? []) : 0;

        return max(1, $n);
    }

    /**
     * Read the agent's transfer log: how many parts are finished and what it is busy with.
     *
     * @param  list<string>  $lines
     * @return array{0: int, 1: int, 2: ?string}
     */
    public static function transferSteps(array $lines, int $total): array
    {
        $done = 0;
        $current = null;
        foreach ($lines as $line) {
            $line = (string) $line;
            if (preg_match('/^(files|database \S+|mail \S+): (done|failed)/', $line) || str_starts_with($line, 'ssl: ')) {
                $done++;
                $current = null;
            } elseif (str_starts_with($line, 'files: ')) {
                $current = __('Copying website files');
            } elseif (preg_match('/^database (\S+) -> /', $line, $m)) {
                $current = __('Copying database :name', ['name' => $m[1]]);
            } elseif (preg_match('/^mail (\S+): start/', $line, $m)) {
                $current = __('Copying mailbox :box', ['box' => $m[1]]);
            }
        }

        return [min($done, $total), $total, $current];
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
