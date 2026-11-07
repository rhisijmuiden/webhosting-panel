<?php

declare(strict_types=1);

namespace Rhis\DiskUsage;

use App\Models\SiteDatabase;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Rhis\DnsMail\Models\Mailbox;

/**
 * What fills a website: its files (from the agent's last disk scan), its
 * databases and its mailboxes. A main website's disk quota also covers the
 * files of its subdomains, so the overview folds subdomains into their parent.
 */
final class DiskUsage
{
    private const MB = 1024 * 1024;

    private ?array $summary = null;

    public function __construct(private readonly AgentClient $agent) {}

    /** disk.summary, once per request; empty when the agent is not reachable. */
    public function summary(): array
    {
        if ($this->summary === null) {
            try {
                $this->summary = $this->agent->call('disk.summary', [], 60) + ['sites' => [], 'databases' => [], 'scanning' => []];
            } catch (AgentException $e) {
                $this->summary = ['sites' => [], 'databases' => [], 'scanning' => [], 'error' => $e->getMessage()];
            }
        }

        return $this->summary;
    }

    /** Bytes of files: the last scan, or the size whp-site reported when it was never scanned. */
    public function filesBytes(Website $site): int
    {
        $scan = $this->summary()['sites'][$site->domain] ?? null;

        return $scan !== null ? (int) $scan['bytes'] : (int) $site->disk_used_mb * self::MB;
    }

    public function scannedAt(Website $site): ?int
    {
        $at = $this->summary()['sites'][$site->domain]['scanned_at'] ?? null;

        return $at !== null ? (int) $at : null;
    }

    /** @return list<array{name: string, bytes: int}> */
    public function databases(Website $site): array
    {
        $sizes = $this->summary()['databases'];

        return SiteDatabase::where('website_id', $site->id)->orderBy('name')->pluck('name')
            ->map(fn ($name) => ['name' => $name, 'bytes' => (int) ($sizes[$name] ?? 0)])
            ->sortByDesc('bytes')->values()->all();
    }

    /** @return list<array{address: string, bytes: int, quota_mb: int, percent: ?int, id: int}> */
    public function mailboxes(Website $site): array
    {
        if (! class_exists(Mailbox::class)) {
            return [];
        }

        return Mailbox::with('domain')->whereHas('domain', fn ($q) => $q->where('website_id', $site->id))->get()
            ->map(fn (Mailbox $m) => [
                'id' => $m->id, 'address' => $m->address(), 'bytes' => (int) $m->used_kb * 1024,
                'quota_mb' => (int) $m->quota_mb, 'percent' => $m->usedPercent(),
            ])->sortByDesc('bytes')->values()->all();
    }

    /** The disk quota of the files in MB (a subdomain shares its parent's), null for unlimited. */
    public function quotaMb(Website $site): ?int
    {
        $main = $site->isSubdomain() && $site->parent ? $site->parent : $site;

        return ($main->disk_quota_mb ?: $main->package?->disk_mb) ?: null;
    }

    /**
     * One row per main website: files (own + subdomains), databases, mail and
     * the share of the disk quota the files take.
     *
     * @param  Collection<int, Website>  $websites
     */
    public function overview(Collection $websites): Collection
    {
        $websites = (new EloquentCollection($websites->all()))->loadMissing(['subdomains', 'customer']);
        $warn = (int) config('disk-usage.warn_percent', 85);
        $ids = $websites->pluck('id')->merge($websites->flatMap(fn (Website $w) => $w->subdomains->pluck('id')))->unique()->all();
        $sizes = $this->summary()['databases'];
        $dbBytes = SiteDatabase::whereIn('website_id', $ids)->get(['website_id', 'name'])->groupBy('website_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => (int) ($sizes[$r->name] ?? 0)));
        $mailBytes = class_exists(Mailbox::class)
            ? Mailbox::query()->join('mail_domains', 'mail_domains.id', '=', 'mailboxes.mail_domain_id')
                ->whereIn('mail_domains.website_id', $ids)->groupBy('mail_domains.website_id')
                ->selectRaw('mail_domains.website_id as website_id, SUM(mailboxes.used_kb) as kb')->pluck('kb', 'website_id')
                ->map(fn ($kb) => (int) $kb * 1024)
            : collect();

        return $websites->reject(fn (Website $w) => $w->isSubdomain() && $websites->contains('id', $w->parent_id))
            ->map(function (Website $w) use ($warn, $dbBytes, $mailBytes) {
                $sites = collect([$w])->merge($w->isSubdomain() ? [] : $w->subdomains);
                $files = $sites->sum(fn ($s) => $this->filesBytes($s));
                $db = $sites->sum(fn ($s) => $dbBytes[$s->id] ?? 0);
                $mail = $sites->sum(fn ($s) => $mailBytes[$s->id] ?? 0);
                $quota = $this->quotaMb($w);
                $percent = $quota ? min(100.0, $files / ($quota * self::MB) * 100) : null;
                $scanned = $sites->map(fn ($s) => $this->scannedAt($s))->filter();

                return [
                    'website' => $w, 'files' => $files, 'databases' => $db, 'mail' => $mail, 'total' => $files + $db + $mail,
                    'quota_bytes' => $quota ? $quota * self::MB : null, 'percent' => $percent,
                    'near_full' => $percent !== null && $percent >= $warn,
                    'subdomains' => $sites->count() - 1,
                    'scanned_at' => $scanned->isEmpty() ? null : $scanned->min(),
                ];
            })->sortByDesc('total')->values();
    }

    /** The agent's scan of a website, opened at one folder. */
    public function folder(Website $site, string $path): array
    {
        try {
            return $this->agent->call('disk.usage', ['domain' => $site->domain, 'path' => $path], 30);
        } catch (AgentException $e) {
            return ['domain' => $site->domain, 'node' => null, 'scanned_at' => null, 'scanning' => false, 'error' => $e->getMessage()];
        }
    }

    /** Start a new scan; returns false when the agent refused. */
    public function scan(Website $site): bool
    {
        try {
            $this->agent->call('disk.scan', ['domain' => $site->domain], 30);

            return true;
        } catch (AgentException) {
            return false;
        }
    }

    /** Clean the ?path= of the drill-down: relative, no "..", no empty parts. */
    public static function cleanPath(?string $path): string
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', (string) $path)), fn ($p) => $p !== '' && $p !== '.' && $p !== '..');

        return mb_substr(implode('/', $parts), 0, 4000);
    }

    /**
     * A hint for a well-known folder, so a customer knows what it is and whether
     * it is safe to clean up: [text, tone] or null.
     */
    public static function folderHint(string $name, string $path): ?array
    {
        $lower = strtolower($name);

        return match (true) {
            $path === 'logs' => ['Visitor and error logs of the website.', 'info'],
            $path === 'tmp', $path === 'sessions' => ['Temporary files and PHP sessions of visitors.', 'info'],
            in_array($lower, ['cache', '.cache', 'wp-rocket-config', 'litespeed'], true) || str_ends_with($path, 'wp-content/cache') => ['Cache: safe to empty, it is rebuilt automatically.', 'ok'],
            in_array($lower, ['backup', 'backups', 'updraft', 'ai1wm-backups', 'backwpup', 'wpvividbackups', 'backups-dup-pro', 'backups-dup-lite'], true) => ['Backups stored inside the website count towards your disk space. The panel already makes backups for you.', 'warn'],
            $lower === 'uploads' => ['Uploaded media, such as images and PDF files.', 'neutral'],
            in_array($lower, ['node_modules', 'vendor'], true) => ['Dependencies of the code (Composer or npm). Needed by the website.', 'neutral'],
            $lower === '.git' => ['Git history of the code.', 'neutral'],
            $lower === '.trash', $lower === 'trash' => ['Deleted files. Empty this folder to free up space.', 'ok'],
            default => null,
        };
    }

    /** Large archives and dumps are often forgotten backups. */
    public static function isArchive(string $name): bool
    {
        return (bool) preg_match('/\.(zip|tar|tgz|gz|bz2|xz|7z|rar|sql|wpress|bak|dump|jpa|daf)$/i', $name);
    }
}
