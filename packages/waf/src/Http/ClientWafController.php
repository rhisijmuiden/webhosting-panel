<?php

declare(strict_types=1);

namespace Rhis\Waf\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Waf\Models\WafEvent;
use Rhis\Waf\Models\WafSite;
use Rhis\Waf\Waf;

/**
 * Customer: what the firewall stopped on their websites, the mode per site and
 * "this was not an attack" exceptions (when the admin lets customers change it).
 */
final class ClientWafController extends Controller
{
    use ManagesExceptions;

    public function __construct(private readonly Waf $waf) {}

    public function index(Request $request): View
    {
        $websites = $request->user()->customer->websites()->orderBy('domain')->get();
        $ids = $websites->pluck('id')->all();
        $counts = WafEvent::query()->whereIn('website_id', $ids)->where('occurred_at', '>=', now()->subDays(30))
            ->selectRaw('website_id, sum(case when blocked then 1 else 0 end) as blocked, sum(case when blocked then 0 else 1 end) as detected')
            ->groupBy('website_id')->get()->keyBy('website_id');

        return view('waf::client.index', [
            'websites' => $websites,
            'rows' => WafSite::whereIn('website_id', $ids)->get()->keyBy('website_id'),
            'counts' => $counts,
            'summary' => $this->waf->summary($ids),
            'perDay' => $this->waf->perDay(14, $ids),
            'waf' => $this->waf,
        ]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        $row = $this->waf->forSite($website);

        return view('waf::client.show', [
            'website' => $website,
            'row' => $row,
            'mode' => $this->waf->modeFor($row->exists ? $row : null),
            'canChange' => $this->waf->clientControl(),
            'events' => WafEvent::where('website_id', $website->id)->latest('occurred_at')->paginate(25),
            'summary' => $this->waf->summary([$website->id]),
        ]);
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeChange($request, $website);

        return $this->saveMode($request, $website, false);
    }

    public function addException(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeChange($request, $website);

        return $this->storeException($request, $website);
    }

    public function removeException(Request $request, Website $website, int $index): RedirectResponse
    {
        $this->authorizeChange($request, $website);

        return $this->destroyException($website, $index);
    }

    public function event(Request $request, Website $website, WafEvent $event): View
    {
        $this->authorizeEvent($request, $website, $event);

        return view('waf::client.event', ['website' => $website, 'event' => $event, 'canChange' => $this->waf->clientControl()]);
    }

    public function allow(Request $request, Website $website, WafEvent $event): RedirectResponse
    {
        $this->authorizeChange($request, $website);
        $this->authorizeEvent($request, $website, $event);

        return $this->allowFromEvent($request, $event, ['page', 'site']);
    }

    private function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
    }

    private function authorizeChange(Request $request, Website $website): void
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->waf->clientControl(), 403);
    }

    private function authorizeEvent(Request $request, Website $website, WafEvent $event): void
    {
        $this->authorizeSite($request, $website);
        abort_unless($event->website_id === $website->id, 404);
    }
}
