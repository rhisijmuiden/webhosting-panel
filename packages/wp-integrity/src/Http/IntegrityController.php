<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\Integrity;
use Rhis\WpIntegrity\Models\IntegrityReport;

/** The integrity screens admins and customers share; the subclasses decide who sees which install. */
abstract class IntegrityController extends Controller
{
    /** 'admin' or 'client': layout and route names. */
    protected string $area;

    public function __construct(protected readonly Integrity $integrity) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    /** The customer whose installs this user may see (null = all, for admins). */
    abstract protected function customerId(Request $request): ?int;

    public function index(Request $request): View|RedirectResponse
    {
        $rows = $this->integrity->overview($this->customerId($request));
        if ($this->area === 'client' && $rows->count() === 1) {
            return redirect()->route('client.wp-integrity.show', $rows->first()['install']);
        }
        $filter = in_array($request->query('filter'), ['changed', 'malware'], true) ? $request->query('filter') : 'all';
        $all = $rows;
        if ($filter === 'changed') {
            $rows = $rows->filter(fn ($r) => $r['report']?->status === 'changed')->values();
        } elseif ($filter === 'malware') {
            $rows = $rows->filter(fn ($r) => ($r['report']?->malware_files ?? 0) > 0)->values();
        }

        return view('wp-integrity::index', [
            'area' => $this->area, 'rows' => $rows, 'filter' => $filter,
            'totals' => [
                'installs' => $all->count(),
                'changed' => $all->filter(fn ($r) => $r['report']?->status === 'changed')->count(),
                'files' => (int) $all->sum(fn ($r) => $r['report']?->changed_files ?? 0),
                'malware' => (int) $all->sum(fn ($r) => $r['report']?->malware_files ?? 0),
                'unchecked' => $all->filter(fn ($r) => ! $r['report']?->checked_at)->count(),
            ],
        ]);
    }

    public function show(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $report = $this->integrity->for($install);
        $job = $report->exists ? $this->integrity->poll($report) : null;
        $report->refresh();
        $report->setRelation('install', $install);

        return view('wp-integrity::show', [
            'area' => $this->area, 'install' => $install, 'report' => $report, 'job' => $job,
            'components' => $report->components(),
            'wordpressUrl' => Route::has($this->area.'.wordpress.show') ? route($this->area.'.wordpress.show', $install) : null,
            'fileManager' => Route::has($this->area.'.files.browse') && config('files.enabled', true)
                ? fn (string $p) => route($this->area.'.files.browse', array_filter([$install->website, 'path' => $p])) : null,
        ]);
    }

    public function check(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        try {
            $this->integrity->start($install);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.integrity_check', $install->label(), [], $install->website->customer_id);

        return redirect()->route($this->area.'.wp-integrity.show', $install)
            ->with('status', __('Checking the files of :site. This takes up to a minute.', ['site' => $install->label()]));
    }

    public function restore(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'component' => ['nullable', 'string', 'max:120'],
            'files' => ['sometimes', 'array', 'max:500'],
            'files.*' => ['string', 'max:1000'],
            'all' => ['sometimes', 'boolean'],
        ]);
        $report = $this->integrity->for($install);
        abort_unless($report->exists, 404);
        if ($report->isRunning()) {
            return back()->withErrors(['agent' => __('A check or restore is already running. Wait until it is ready.')]);
        }
        $items = $this->selection($report, $request->boolean('all') ? null : (string) ($data['component'] ?? ''), $data['files'] ?? []);
        if ($items === []) {
            return back()->withErrors(['agent' => __('Choose the files to restore.')]);
        }
        try {
            $this->integrity->restore($install, $items);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('wordpress.integrity_restore', $install->label(), [
            'components' => array_map(fn ($i) => $i['type'].':'.$i['slug'], $items),
            'files' => array_sum(array_map(fn ($i) => count($i['files']), $items)),
        ], $install->website->customer_id);

        return redirect()->route($this->area.'.wp-integrity.show', $install)
            ->with('status', __('Putting the original files back. The files are checked again afterwards.'));
    }

    public function accept(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        $data = $request->validate([
            'component' => ['required', 'string', 'max:120'],
            'files' => ['required', 'array', 'max:500'],
            'files.*' => ['string', 'max:1000'],
            'action' => ['required', Rule::in(['accept', 'undo'])],
        ]);
        $report = $this->integrity->for($install);
        abort_unless($report->exists, 404);
        $keys = [];
        foreach ($report->report['components'] ?? [] as $c) {
            if ($c['type'].':'.$c['slug'] !== $data['component']) {
                continue;
            }
            foreach ($c['files'] ?? [] as $f) {
                if (in_array($f['file'], $data['files'], true)) {
                    $keys[] = IntegrityReport::acceptKey($c, $f);
                }
            }
        }
        $n = $this->integrity->accept($report, $keys, $data['action'] === 'accept');
        ActivityLog::record('wordpress.integrity_'.$data['action'], $install->label(), ['component' => $data['component'], 'files' => $n], $install->website->customer_id);

        return back()->with('status', $data['action'] === 'accept'
            ? trans_choice(':count file is marked as an intended change.|:count files are marked as an intended change.', $n, ['count' => $n])
            : trans_choice(':count file counts as changed again.|:count files count as changed again.', $n, ['count' => $n]));
    }

    /**
     * What to send to wpint.restore: the chosen files of one component, or every
     * changed file that was not accepted ($component = null).
     */
    private function selection(IntegrityReport $report, ?string $component, array $files): array
    {
        $items = [];
        foreach ($report->components() as $c) {
            $key = $c['type'].':'.$c['slug'];
            if ($c['status'] !== 'changed' || ($component !== null && $key !== $component)) {
                continue;
            }
            $open = collect($c['files'])->reject(fn ($f) => $f['accepted']);
            $chosen = $component === null ? $open : $open->whereIn('file', $files);
            if ($chosen->isEmpty()) {
                continue;
            }
            $items[] = [
                'type' => $c['type'], 'slug' => $c['slug'],
                'files' => $chosen->pluck('file')->values()->all(),
                'quarantine_added' => $chosen->contains(fn ($f) => $f['issue'] === 'added'),
            ];
        }

        return $items;
    }
}
