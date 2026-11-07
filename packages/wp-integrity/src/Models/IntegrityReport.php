<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * The last integrity check of one WordPress install (what wpint.check returned),
 * plus the changes the customer or an admin accepted as their own work.
 */
class IntegrityReport extends Model
{
    protected $table = 'wp_integrity_reports';

    protected $fillable = [
        'wordpress_install_id', 'status', 'changed_files', 'malware_files', 'unverifiable', 'report', 'accepted',
        'job_id', 'job_kind', 'job_started_at', 'last_restore', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'report' => 'array', 'accepted' => 'array', 'last_restore' => 'array',
            'checked_at' => 'datetime', 'job_started_at' => 'datetime',
            'changed_files' => 'integer', 'malware_files' => 'integer', 'unverifiable' => 'integer',
        ];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    public static function acceptKey(array $component, array $file): string
    {
        return $component['type'].':'.$component['slug'].'|'.$file['file'].'|'.($file['md5'] ?? '');
    }

    /**
     * The components of the report, with accepted files marked and a status that
     * leaves accepted files out: ok | changed | unverifiable | error.
     *
     * @return list<array>
     */
    public function components(): array
    {
        $accepted = array_flip($this->accepted ?? []);
        $out = [];
        foreach ($this->report['components'] ?? [] as $c) {
            $open = 0;
            $malware = 0;
            foreach ($c['files'] ?? [] as $i => $f) {
                // A file that is malware or missing cannot be accepted.
                $isAccepted = empty($f['malware']) && ($f['issue'] ?? '') !== 'missing' && isset($f['md5'])
                    && isset($accepted[self::acceptKey($c, $f)]);
                $c['files'][$i]['accepted'] = $isAccepted;
                if (! $isAccepted) {
                    $open++;
                }
                if (! empty($f['malware'])) {
                    $malware++;
                }
            }
            $hidden = max(0, (int) ($c['total_files'] ?? 0) - count($c['files'] ?? []));
            $c['open_files'] = $open + $hidden;
            $c['malware_files'] = $malware;
            $c['accepted_files'] = count($c['files'] ?? []) - $open;
            if (($c['status'] ?? '') === 'changed' && $c['open_files'] === 0) {
                $c['status'] = 'ok';
            }
            $out[] = $c;
        }

        return $out;
    }

    /** Recompute the stored totals from the report and the accepted list. */
    public function recount(): void
    {
        $components = collect($this->components());
        $this->changed_files = (int) $components->where('status', 'changed')->sum('open_files');
        $this->malware_files = (int) $components->sum('malware_files');
        $this->unverifiable = $components->where('status', 'unverifiable')->count();
        $core = $components->firstWhere('type', 'core');
        $this->status = match (true) {
            $this->report === null || empty($this->report['checked_at']) => 'unknown',
            $this->changed_files > 0 => 'changed',
            ($core['status'] ?? null) === 'error' => 'error',
            default => 'ok',
        };
    }

    public function isRunning(): bool
    {
        return $this->job_id !== null && $this->job_started_at !== null && $this->job_started_at->gt(now()->subHour());
    }
}
