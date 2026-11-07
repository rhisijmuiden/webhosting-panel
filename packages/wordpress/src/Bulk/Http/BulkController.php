<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Http;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Bulk\BulkAction;
use Rhis\WordPress\Bulk\BulkActions;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Models\BulkRunItem;
use Rhis\WordPress\Bulk\Runner;
use Rhis\WordPress\Models\WordPressInstall;

/** Admin page: pick an action, pick sites (with filters), follow the progress per site. */
final class BulkController extends Controller
{
    public function __construct(private readonly Runner $runner) {}

    public function index(Request $request): View
    {
        $installs = WordPressInstall::with('website.customer')->get()->sortBy(fn ($i) => $i->label())->values();
        $sites = $installs->map(fn (WordPressInstall $i) => [
            'id' => $i->id,
            'label' => $i->label(),
            'customer' => $i->website->customer?->displayName(),
            'customer_id' => $i->website->customer_id,
            'status' => $i->website->status,
            'version' => $i->version,
            'core_update' => $i->core_update,
            'outdated_plugins' => $i->outdated_plugins,
            'outdated_themes' => $i->outdated_themes,
            'outdated' => $i->outdatedCount(),
            'checked' => $i->checked_at !== null,
            'error' => $i->last_error !== null,
            'cron' => $i->cron_mode === 'system' ? 'system' : 'wordpress',
            'last_update' => $i->last_update_status,
            'plugins' => collect($i->plugins ?? [])->map(fn ($p) => ($p['name'] ?? '').(($p['update'] ?? false) ? '*' : ''))->filter()->values(),
            'themes' => collect($i->themes ?? [])->map(fn ($p) => ($p['name'] ?? '').(($p['update'] ?? false) ? '*' : ''))->filter()->values(),
            'url' => route('admin.wordpress.show', $i),
        ]);
        $components = $installs->flatMap(fn ($i) => [
            ...collect($i->plugins ?? [])->map(fn ($p) => ['type' => 'plugin', 'name' => $p['name'] ?? '', 'title' => $p['title'] ?? ''])->all(),
            ...collect($i->themes ?? [])->map(fn ($p) => ['type' => 'theme', 'name' => $p['name'] ?? '', 'title' => $p['title'] ?? ''])->all(),
        ])->filter(fn ($p) => $p['name'] !== '')->unique(fn ($p) => $p['type'].':'.$p['name'])
            ->sortBy(fn ($p) => mb_strtolower($p['title'] ?: $p['name']))->values();

        $actions = BulkActions::all();
        $chosen = $request->query('action');

        return view('wordpress::bulk.index', [
            'grouped' => BulkActions::grouped(),
            'actions' => $actions,
            'actionMeta' => collect($actions)->map(fn (BulkAction $a) => [
                'label' => $a->label(), 'dangerous' => $a->dangerous(), 'concurrency' => $a->concurrency(), 'perWebsite' => $a->perWebsite(),
            ])->all(),
            'chosen' => is_string($chosen) && isset($actions[$chosen]) ? $chosen : (old('action') ?: null),
            'prefill' => array_filter(['slugs' => $request->query('slugs'), 'type' => $request->query('type')], 'is_string'),
            'sites' => $sites,
            'components' => $components,
            'customers' => Customer::whereIn('id', $installs->pluck('website.customer_id')->filter()->unique())->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->displayName()])->sortBy('name')->values(),
            'versions' => $installs->pluck('version')->filter()->unique()->sort(SORT_NATURAL)->reverse()->values(),
            'initialFilter' => in_array($request->query('filter'), ['outdated', 'core', 'failed', 'unchecked'], true) ? $request->query('filter') : 'all',
            'initialComponent' => is_string($request->query('has')) ? $request->query('has') : '',
            'runs' => BulkRun::latest('id')->limit(15)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(array_keys(BulkActions::all()))],
            'ids' => ['required', 'array', 'min:1', 'max:2000'],
            'ids.*' => ['integer'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:10'],
            'stop_after_failures' => ['nullable', 'integer', 'min:0', 'max:100'],
            'options' => ['nullable', 'array'],
            'confirm' => ['nullable', 'string', 'max:50'],
        ], ['ids.required' => __('Select at least one site.'), 'action.required' => __('Choose an action.')]);

        $action = BulkActions::find($data['action']);
        $options = $this->options($action, (array) ($data['options'][$action->key()] ?? []));

        if ($action->dangerous() && mb_strtolower(trim((string) ($data['confirm'] ?? ''))) !== mb_strtolower(__('confirm'))) {
            return back()->withInput()->withErrors(['confirm' => __('Type :word to confirm.', ['word' => __('confirm')])]);
        }
        $installs = WordPressInstall::with('website.customer')->whereIn('id', $data['ids'])->get();
        if ($installs->isEmpty()) {
            return back()->withInput()->withErrors(['ids' => __('Select at least one site.')]);
        }
        $run = $this->runner->start($action, $options, $installs, (int) ($data['concurrency'] ?? $action->concurrency()),
            (int) ($data['stop_after_failures'] ?? 0), $request->user());

        return redirect()->route('admin.wordpress.bulk.show', $run);
    }

    public function show(BulkRun $run): View
    {
        return view('wordpress::bulk.run', ['run' => $run, 'payload' => $this->payload($run)]);
    }

    public function status(BulkRun $run): JsonResponse
    {
        return response()->json($this->payload($run));
    }

    public function cancel(BulkRun $run): RedirectResponse
    {
        $this->runner->cancel($run);

        return back()->with('status', __('The run is cancelled. Sites that were busy finish first.'));
    }

    public function resume(BulkRun $run): RedirectResponse
    {
        $this->runner->resume($run);

        return back()->with('status', __('The run continues.'));
    }

    /** A new run with the same action and options on the sites that failed. */
    public function retry(BulkRun $run): RedirectResponse
    {
        $action = $run->definition();
        abort_if($action === null, 404);
        $ids = $run->items()->reorder()->whereIn('status', ['failed', 'cancelled'])->whereNotNull('wordpress_install_id')->pluck('wordpress_install_id');
        $installs = WordPressInstall::with('website.customer')->whereIn('id', $ids)->get();
        if ($installs->isEmpty()) {
            return back()->with('status', __('Nothing to retry.'));
        }
        $new = $this->runner->start($action, $run->options ?? [], $installs, $run->concurrency, $run->stop_after_failures, request()->user());

        return redirect()->route('admin.wordpress.bulk.show', $new);
    }

    // ------------------------------------------------------------------

    /** Validate the options of the chosen action; missing checkboxes are false, other missing fields get their default. */
    private function options(BulkAction $action, array $input): array
    {
        $values = [];
        foreach ($action->fields() as $f) {
            if ($f['type'] === 'note' || $f['name'] === '') {
                continue;
            }
            $values[$f['name']] = match ($f['type']) {
                'checkbox' => filter_var($input[$f['name']] ?? false, FILTER_VALIDATE_BOOLEAN),
                'number' => isset($input[$f['name']]) && $input[$f['name']] !== '' ? $input[$f['name']] : $f['default'],
                default => $input[$f['name']] ?? $f['default'],
            };
        }
        $values = $values + array_intersect_key($input, $action->rules());
        $rules = collect($action->rules())->mapWithKeys(fn ($r, $k) => ["options.{$k}" => $r])->all();
        $names = collect($action->fields())->filter(fn ($f) => $f['name'] !== '')
            ->mapWithKeys(fn ($f) => ["options.{$f['name']}" => mb_strtolower($f['label'])])->all();
        Validator::make(['options' => $values], $rules, [], $names)->validate();

        foreach ($action->fields() as $f) {
            if ($f['type'] === 'number' && isset($values[$f['name']])) {
                $values[$f['name']] = (int) $values[$f['name']];
            }
        }

        return $values;
    }

    private function payload(BulkRun $run): array
    {
        $run->refresh();

        return [
            'run' => [
                'id' => $run->id, 'status' => $run->status, 'status_label' => $run->statusLabel(), 'stop_reason' => $run->stop_reason,
                'finished' => $run->finished_at !== null, 'counts' => $run->counts(),
                'elapsed' => $run->started_at ? (int) $run->started_at->diffInSeconds($run->finished_at ?? now(), true) : 0,
            ],
            'items' => $run->items()->get()->map(fn (BulkRunItem $i) => $i->toPublic())->all(),
        ];
    }
}
