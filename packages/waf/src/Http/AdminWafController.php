<?php

declare(strict_types=1);

namespace Rhis\Waf\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Waf\Models\WafEvent;
use Rhis\Waf\Models\WafSite;
use Rhis\Waf\Waf;

/** Admin: firewall overview with events, mode per website, exceptions and server settings. */
final class AdminWafController extends Controller
{
    use ManagesExceptions;

    public function __construct(private readonly Waf $waf) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['type', 'rule', 'ip', 'site']);

        return view('waf::admin.index', [
            'status' => $this->waf->status(),
            'summary' => $this->waf->summary(),
            'perDay' => $this->waf->perDay(14),
            'events' => WafEvent::query()->with('website:id,domain')->filter($filters)->latest('occurred_at')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'sites' => Website::query()->whereIn('id', WafEvent::query()->whereNotNull('website_id')->distinct()->pluck('website_id'))->orderBy('domain')->pluck('domain', 'id'),
            'last' => $this->waf->lastApply(),
        ]);
    }

    public function sites(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $websites = Website::query()->with('customer')
            ->when($search !== '', fn ($q) => $q->where('domain', 'like', '%'.$search.'%'))
            ->orderBy('domain')->paginate(50)->withQueryString();
        $ids = $websites->pluck('id');
        $counts = WafEvent::query()->whereIn('website_id', $ids)->where('occurred_at', '>=', now()->subDays(7))
            ->selectRaw('website_id, sum(case when blocked then 1 else 0 end) as blocked, sum(case when blocked then 0 else 1 end) as detected')
            ->groupBy('website_id')->get()->keyBy('website_id');

        return view('waf::admin.sites', [
            'websites' => $websites,
            'rows' => WafSite::whereIn('website_id', $ids)->get()->keyBy('website_id'),
            'counts' => $counts,
            'search' => $search,
            'defaultMode' => $this->waf->defaultMode(),
            'waf' => $this->waf,
        ]);
    }

    public function site(Website $website): View
    {
        $row = $this->waf->forSite($website);

        return view('waf::admin.site', [
            'website' => $website,
            'row' => $row,
            'mode' => $this->waf->modeFor($row->exists ? $row : null),
            'defaultMode' => $this->waf->defaultMode(),
            'events' => WafEvent::where('website_id', $website->id)->latest('occurred_at')->paginate(25),
            'summary' => $this->waf->summary([$website->id]),
        ]);
    }

    public function updateSite(Request $request, Website $website): RedirectResponse
    {
        return $this->saveMode($request, $website, true);
    }

    public function addSiteException(Request $request, Website $website): RedirectResponse
    {
        return $this->storeException($request, $website);
    }

    public function removeSiteException(Website $website, int $index): RedirectResponse
    {
        return $this->destroyException($website, $index);
    }

    public function settings(): View
    {
        return view('waf::admin.settings', [
            'defaultMode' => $this->waf->defaultMode(),
            'paranoia' => $this->waf->paranoia(),
            'threshold' => $this->waf->threshold(),
            'clientControl' => $this->waf->clientControl(),
            'exceptions' => $this->waf->exceptions(),
            'status' => $this->waf->status(),
            'last' => $this->waf->lastApply(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_mode' => ['required', 'in:'.implode(',', Waf::MODES)],
            'paranoia' => ['required', 'integer', 'between:1,4'],
            'threshold' => ['required', 'integer', 'between:2,100'],
            'client_control' => ['required', 'boolean'],
        ]);
        $this->waf->saveSettings($data['default_mode'], (int) $data['paranoia'], (int) $data['threshold'], (bool) $data['client_control']);
        ActivityLog::record('waf.settings', null, $data);

        return $this->applied(__('Firewall settings saved.'));
    }

    public function addException(Request $request): RedirectResponse
    {
        $data = $request->validate(Waf::exceptionRules());
        $this->waf->saveExceptions(Waf::addTo($this->waf->exceptions(), Waf::exception((int) $data['rule'], $data['path'] ?? null, $data['param'] ?? null, $data['comment'] ?? null)));
        ActivityLog::record('waf.exception_added', null, ['rule' => (int) $data['rule'], 'path' => $data['path'] ?? null, 'param' => $data['param'] ?? null]);

        return $this->applied(__('Exception for rule :rule added.', ['rule' => $data['rule']]));
    }

    public function removeException(int $index): RedirectResponse
    {
        $list = $this->waf->exceptions();
        abort_unless(isset($list[$index]), 404);
        $removed = $list[$index];
        unset($list[$index]);
        $this->waf->saveExceptions($list);
        ActivityLog::record('waf.exception_removed', null, ['rule' => $removed['rule']]);

        return $this->applied(__('Exception for rule :rule removed.', ['rule' => $removed['rule']]));
    }

    public function event(WafEvent $event): View
    {
        return view('waf::admin.event', ['event' => $event->load('website.customer')]);
    }

    public function allow(Request $request, WafEvent $event): RedirectResponse
    {
        return $this->allowFromEvent($request, $event, ['page', 'site', 'server']);
    }

    public function apply(): RedirectResponse
    {
        return $this->applied(__('Firewall settings sent to the server.'));
    }

    public function collect(): RedirectResponse
    {
        try {
            $n = $this->waf->collect();
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }

        return back()->with('status', trans_choice(':count new event fetched.|:count new events fetched.', $n));
    }
}
