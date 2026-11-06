<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Http;

use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\Monitoring\Alerts;
use Rhis\Monitoring\Components;
use Rhis\Monitoring\Models\Channel;
use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Incident;
use Rhis\Monitoring\Models\Notice;
use Rhis\Monitoring\Models\Sample;
use Rhis\Monitoring\Monitor;
use Rhis\Monitoring\Options;
use Rhis\Monitoring\Teams;
use Rhis\Monitoring\Uptime;
use Throwable;

class AdminController extends Controller
{
    public function __construct(private readonly Options $options) {}

    public function index(Request $request): View
    {
        $checks = Check::with('website.customer')->get();
        $sites = $checks->where('kind', 'website')->sortBy([fn ($a, $b) => \Rhis\Monitoring\Severity::rank($b->status) <=> \Rhis\Monitoring\Severity::rank($a->status), ['label', 'asc']]);
        $ssl = $checks->where('kind', 'ssl')->keyBy('website_id');
        $ids = $sites->pluck('website_id')->filter()->all();
        $uptime30 = $this->uptimeMap($ids, 30);
        $uptime1 = $this->uptimeMap($ids, 1);
        $filter = (string) $request->query('filter', '');
        if ($filter === 'problems') {
            $sites = $sites->filter(fn (Check $c) => $c->isProblem() || ($ssl->get($c->website_id)?->isProblem()));
        } elseif ($q = trim((string) $request->query('q', ''))) {
            $sites = $sites->filter(fn (Check $c) => str_contains($c->label, mb_strtolower($q)));
        }

        return view('monitoring::admin.index', [
            'o' => $this->options,
            'sites' => $sites->values(),
            'ssl' => $ssl,
            'uptime30' => $uptime30,
            'uptime1' => $uptime1,
            'disks' => $checks->where('kind', 'disk')->sortBy('label')->values(),
            'inodes' => $checks->where('kind', 'inode')->keyBy('label'),
            'services' => $checks->where('kind', 'service')->sortBy('label')->values(),
            'server' => $checks->whereIn('kind', ['memory', 'load', 'mail_queue'])->keyBy('kind'),
            'agent' => $checks->firstWhere('key', 'agent'),
            'open' => Incident::open()->with('website')->latest('started_at')->get(),
            'total' => $checks->where('kind', 'website')->count(),
            'down' => $checks->where('kind', 'website')->where('status', 'critical')->count(),
            'sslSoon' => $ssl->filter->isProblem()->count(),
            'last' => $this->options->state('last_result'),
            'paused' => app(Monitor::class)->paused(),
            'filter' => $filter,
        ]);
    }

    /** @param list<int> $ids @return array<int, ?float> */
    private function uptimeMap(array $ids, int $days): array
    {
        if (! $ids) {
            return [];
        }
        $from = now()->timezone(config('app.timezone'))->subDays($days - 1)->toDateString();

        return \Rhis\Monitoring\Models\Daily::whereIn('website_id', $ids)->where('key', 'like', 'site:%')->where('day', '>=', $from)
            ->selectRaw('website_id, SUM(checks) c, SUM(up) u')->groupBy('website_id')->get()
            ->mapWithKeys(fn ($r) => [$r->website_id => $r->c ? round($r->u * 100 / $r->c, 2) : null])->all();
    }

    public function runNow(Monitor $monitor): RedirectResponse
    {
        try {
            $r = $monitor->run();

            return back()->with('status', __('Checked: :n checks, :p problems.', ['n' => $r['checked'], 'p' => $r['problems']]));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('Checking failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function website(Website $website): View
    {
        $samples = Sample::where('website_id', $website->id)->where('checked_at', '>=', now()->subDay())->orderBy('checked_at')->get();

        return view('monitoring::admin.website', [
            'website' => $website->load('customer'),
            'check' => Check::where('key', "site:{$website->id}")->first(),
            'ssl' => Check::where('key', "ssl:{$website->id}")->first(),
            'days' => Uptime::days("site:{$website->id}", 90),
            'samples' => $samples,
            'incidents' => Incident::where('website_id', $website->id)->latest('started_at')->limit(50)->get(),
        ]);
    }

    public function incidents(Request $request): View
    {
        $q = Incident::with('website')->latest('started_at');
        if ($kind = $request->query('kind')) {
            $q->where('kind', $kind);
        }
        if ($request->query('state') === 'open') {
            $q->whereNull('resolved_at');
        }

        return view('monitoring::admin.incidents', ['incidents' => $q->paginate(50)->withQueryString(), 'kind' => $kind, 'state' => $request->query('state')]);
    }

    // ------------------------------------------------------------ alert channels

    public function alerts(): View
    {
        return view('monitoring::admin.alerts', ['channels' => Channel::orderBy('id')->get(), 'o' => $this->options]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $data = $this->validateChannel($request, true);
        $channel = Channel::create($data);

        return redirect()->route('admin.monitoring.alerts')->with('status', __('Alert channel :name added. Send a test message to check it.', ['name' => $channel->name]));
    }

    public function updateChannel(Request $request, Channel $channel): RedirectResponse
    {
        if ($request->has('toggle')) {
            $channel->update(['enabled' => ! $channel->enabled]);

            return back()->with('status', $channel->enabled ? __('Alert channel switched on.') : __('Alert channel switched off.'));
        }
        $data = $this->validateChannel($request, false, $channel);
        if (($data['target'] ?? null) === null && $channel->type === 'teams') {
            unset($data['target']); // keep the stored webhook when the field is left empty
        }
        $channel->update($data);

        return back()->with('status', __('Alert channel saved.'));
    }

    private function validateChannel(Request $request, bool $new, ?Channel $channel = null): array
    {
        $type = $new ? (string) $request->input('type') : $channel->type;
        $data = $request->validate([
            'type' => $new ? ['required', Rule::in(Channel::TYPES)] : ['nullable'],
            'name' => ['required', 'string', 'max:120'],
            'target' => $type === 'teams' ? [$new ? 'required' : 'nullable', 'string', 'max:2000', function ($attr, $value, $fail) {
                if (! Teams::validUrl((string) $value)) {
                    $fail(__('Use the https web address of a Teams workflow (Workflows > Post to a channel when a webhook request is received).'));
                }
            }] : ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) {
                foreach (preg_split('/[,;\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $m) {
                    if (! filter_var($m, FILTER_VALIDATE_EMAIL)) {
                        $fail(__(':address is not a valid email address.', ['address' => $m]));
                    }
                }
            }],
            'min_severity' => ['required', Rule::in(['warning', 'critical'])],
            'locale' => ['required', Rule::in(['en', 'nl'])],
            'recoveries' => ['nullable', 'boolean'],
        ]);
        $data['recoveries'] = $request->boolean('recoveries');
        $data['type'] = $type;
        $data['target'] = isset($data['target']) && trim((string) $data['target']) !== '' ? trim((string) $data['target']) : null;

        return $data;
    }

    public function testChannel(Channel $channel, Alerts $alerts): RedirectResponse
    {
        return $alerts->test($channel)
            ? back()->with('status', __('Test message sent to :name.', ['name' => $channel->name]))
            : back()->with('error', __('Sending to :name failed: :error', ['name' => $channel->name, 'error' => $channel->fresh()->last_error]));
    }

    public function destroyChannel(Channel $channel): RedirectResponse
    {
        $channel->delete();

        return back()->with('status', __('Alert channel removed.'));
    }

    // ------------------------------------------------------------ settings

    public function settings(): View
    {
        return view('monitoring::admin.settings', ['o' => $this->options, 'heartbeat' => $this->options->state('heartbeat')]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'interval' => ['required', 'integer', Rule::in([1, 2, 5, 10, 15])],
            'fail_after' => ['required', 'integer', 'min:1', 'max:10'],
            'slow_ms' => ['required', 'integer', 'min:200', 'max:60000'],
            'reminder_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'disk_warning' => ['required', 'integer', 'min:50', 'max:99'],
            'disk_critical' => ['required', 'integer', 'min:50', 'max:100', 'gt:disk_warning'],
            'inode_warning' => ['required', 'integer', 'min:50', 'max:100'],
            'memory_warning' => ['required', 'integer', 'min:50', 'max:100'],
            'load_warning' => ['required', 'numeric', 'min:0.5', 'max:50'],
            'mail_queue_warning' => ['required', 'integer', 'min:10', 'max:100000'],
            'ssl_warning_days' => ['required', 'integer', 'min:1', 'max:60'],
            'ssl_critical_days' => ['required', 'integer', 'min:0', 'max:30', 'lt:ssl_warning_days'],
            'heartbeat_url' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '' && ! Teams::validUrl((string) $value)) {
                    $fail(__('Use an https address.'));
                }
            }],
            'status_title' => ['required', 'string', 'max:120'],
        ]);
        foreach (['enabled', 'customer_alerts', 'status_page'] as $bool) {
            $data[$bool] = $request->boolean($bool);
        }
        $data['load_warning'] = (float) $data['load_warning'];
        $data['heartbeat_url'] = trim((string) ($data['heartbeat_url'] ?? ''));
        foreach ($data as $k => $v) {
            $this->options->set($k, $v);
        }

        return back()->with('status', __('Monitoring settings saved.'));
    }

    // ------------------------------------------------------------ status page

    public function statusPage(): View
    {
        return view('monitoring::admin.status', [
            'o' => $this->options,
            'components' => Components::overview(30),
            'notices' => Notice::latest('id')->limit(30)->get(),
        ]);
    }

    public function storeNotice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'level' => ['required', Rule::in(Notice::LEVELS)],
            'components' => ['nullable', 'array'],
            'components.*' => [Rule::in(array_keys(Components::all()))],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        Notice::create($data + ['components' => []]);

        return back()->with('status', __('Message placed on the status page.'));
    }

    public function resolveNotice(Notice $notice): RedirectResponse
    {
        $notice->update(['resolved_at' => now()]);

        return back()->with('status', __('Message marked as solved.'));
    }

    public function destroyNotice(Notice $notice): RedirectResponse
    {
        $notice->delete();

        return back()->with('status', __('Message removed.'));
    }
}
