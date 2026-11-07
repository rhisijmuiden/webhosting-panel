<?php

declare(strict_types=1);

namespace Rhis\DiskUsage\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Rhis\DiskUsage\DiskUsage;

/** Disk usage overview and explorer, shared by the admin and the client panel. */
abstract class DiskUsageController extends Controller
{
    /** 'admin' or 'client': layout and route names. */
    protected string $area;

    public function __construct(protected readonly DiskUsage $usage) {}

    /** The websites this user may see. */
    abstract protected function websites(Request $request): Collection;

    abstract protected function allowed(Request $request, Website $website): bool;

    /** May this user see database and mailbox names? */
    protected function sees(string $what): bool
    {
        return true;
    }

    public function index(Request $request): View|RedirectResponse
    {
        $websites = $this->websites($request);
        $rows = $this->usage->overview($websites);
        if ($this->area === 'client' && $rows->count() === 1) {
            return redirect()->route('client.disk-usage.show', $rows->first()['website']);
        }
        $all = $rows;
        if ($q = trim((string) $request->query('q'))) {
            $rows = $rows->filter(fn ($r) => str_contains($r['website']->domain, strtolower($q))
                || str_contains(strtolower((string) $r['website']->customer?->displayName()), strtolower($q)))->values();
        }
        if ($request->boolean('full')) {
            $rows = $rows->where('near_full', true)->values();
        }

        return view('disk-usage::index', [
            'area' => $this->area, 'rows' => $rows, 'q' => $q, 'full' => $request->boolean('full'),
            'totals' => [
                'files' => $all->sum('files'), 'databases' => $all->sum('databases'), 'mail' => $all->sum('mail'),
                'near_full' => $all->where('near_full', true)->count(), 'websites' => $all->count(),
            ],
            'agentError' => $this->usage->summary()['error'] ?? null,
        ]);
    }

    public function show(Request $request, Website $website): View
    {
        abort_unless($this->allowed($request, $website), 404);
        $path = DiskUsage::cleanPath($request->query('path'));
        $scan = $this->usage->folder($website, $path);
        if (($scan['node'] ?? null) === null && ! ($scan['scanning'] ?? false) && ! isset($scan['error'])) {
            $scan['scanning'] = $this->usage->scan($website);
        }
        $main = $website->isSubdomain() && $website->parent ? $website->parent : $website;
        $parts = $path === '' ? [] : explode('/', (string) ($scan['node']['path'] ?? ''));
        $crumbs = [];
        foreach ($parts as $i => $part) {
            $crumbs[] = ['name' => $part, 'path' => implode('/', array_slice($parts, 0, $i + 1))];
        }
        $fileRoute = $this->area.'.files.browse';

        return view('disk-usage::show', [
            'area' => $this->area, 'website' => $website, 'main' => $main, 'scan' => $scan, 'path' => $path, 'crumbs' => $crumbs,
            'subscription' => $this->usage->overview(collect([$main]))->first(),
            'filesBytes' => (int) ($scan['total_bytes'] ?? $this->usage->filesBytes($website)),
            'databases' => $this->sees('databases') ? $this->usage->databases($website) : null,
            'mailboxes' => $this->sees('mail') ? $this->usage->mailboxes($website) : null,
            'subdomains' => $website->isSubdomain() ? collect() : $website->subdomains->map(fn ($s) => ['website' => $s, 'bytes' => $this->usage->filesBytes($s)])->sortByDesc('bytes')->values(),
            'fileManager' => Route::has($fileRoute) && $this->sees('files') && config('files.enabled', true)
                ? fn (string $p) => route($fileRoute, array_filter([$website, 'path' => $p])) : null,
        ]);
    }

    public function scan(Request $request, Website $website): RedirectResponse
    {
        abort_unless($this->allowed($request, $website), 404);
        $ok = $this->usage->scan($website);

        return redirect()->route($this->area.'.disk-usage.show', array_filter([$website, 'path' => DiskUsage::cleanPath($request->input('path'))]))
            ->with($ok ? 'status' : 'warning', $ok
                ? __('Scanning :domain. This page refreshes by itself when the scan is ready.', ['domain' => $website->domain])
                : __('The scan could not be started. Try again in a minute.'));
    }
}
