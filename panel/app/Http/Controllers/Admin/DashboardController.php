<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Metrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Rhis\Vulnerabilities\Risk;
use Rhis\WordPress\Toolkit;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(AgentClient $agent, Metrics $metrics): View
    {
        $system = $services = $security = null;
        $agentError = null;
        try {
            $system = $agent->call('system.info');
            $services = $agent->call('service.list')['services'] ?? [];
            $security = $agent->call('crowdsec.metrics');
        } catch (AgentException $e) {
            $agentError = $e->getMessage();
        }

        $points = $metrics->serverSeries('24h')
            ->map(fn ($p) => $p + ['label' => Carbon::createFromTimestamp($p['t'])->setTimezone(config('app.timezone'))->isoFormat('HH:mm')]);

        return view('admin.dashboard', [
            'system' => $system,
            'services' => $services,
            'security' => $security,
            'agentError' => $agentError,
            'counts' => [
                'customers' => Customer::count(),
                'customers_30d' => Customer::where('created_at', '>=', now()->subDays(30))->count(),
                'websites' => Website::count(),
                'ssl_pending' => Website::where('ssl_status', '!=', 'active')->count(),
                'php_versions' => Website::selectRaw('php_version, count(*) as total')->groupBy('php_version')->pluck('total', 'php_version'),
            ],
            'points' => $points,
            'posture' => $this->posture(),
            'attention' => $this->attention($services, $agentError),
            'latestCustomers' => Customer::withCount('websites')->latest()->limit(6)->get(),
        ]);
    }

    /** The security score, only when the Security center has worked it out recently (it can take a while). */
    private function posture(): ?array
    {
        if (! Route::has('admin.vulns.index')) {
            return null;
        }
        $p = Cache::get('vulnerabilities.posture');

        return is_array($p) && isset($p['score']) ? $p : null;
    }

    /**
     * Things the admin should look at, most urgent first.
     *
     * @return list<array{tone: string, icon: string, title: string, detail: string, url: ?string, action: string}>
     */
    private function attention(?array $services, ?string $agentError): array
    {
        $items = [];
        if ($agentError) {
            $items[] = ['tone' => 'bad', 'icon' => 'server', 'title' => __('The server agent is not reachable.'), 'detail' => $agentError, 'url' => route('admin.services.index'), 'action' => __('Open')];
        }
        foreach ($services ?? [] as $s) {
            if (($s['installed'] ?? true) && ! ($s['active'] ?? false)) {
                $items[] = ['tone' => 'bad', 'icon' => 'server', 'title' => __(':service is stopped', ['service' => $s['label']]), 'detail' => __('Restart it from Services.'), 'url' => route('admin.services.index'), 'action' => __('Open')];
            }
        }

        if (class_exists(Risk::class) && Route::has('admin.vulns.index')) {
            try {
                foreach (Risk::ranking(3) as $r) {
                    if (! in_array($r['level'], ['critical', 'high'], true)) {
                        continue;
                    }
                    $what = $r['top'] ? ($r['top']->isMalware() ? __('Suspicious file') : $r['top']->componentLabel()) : '';
                    $items[] = ['tone' => $r['level'] === 'critical' ? 'bad' : 'warn', 'icon' => 'shield',
                        'title' => $r['customer']->displayName(), 'detail' => __('Risk :n', ['n' => $r['score']]).($what ? ' · '.$what : ''),
                        'url' => route('admin.vulns.findings', ['customer' => $r['customer']->id]), 'action' => __('Fix')];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $noSsl = Website::where('ssl_status', '!=', 'active')->count();
        if ($noSsl > 0) {
            $items[] = ['tone' => 'warn', 'icon' => 'lock', 'title' => trans_choice(':count website without a certificate|:count websites without a certificate', $noSsl),
                'detail' => __('Usually DNS does not point to this server yet.'), 'url' => route('admin.websites.index'), 'action' => __('Check')];
        }

        if (class_exists(Toolkit::class) && Route::has('admin.wordpress.index')) {
            try {
                $wp = Toolkit::summary();
                if ($wp['outdated_installs'] > 0) {
                    $items[] = ['tone' => 'warn', 'icon' => 'wordpress', 'title' => __(':n of :total sites outdated', ['n' => $wp['outdated_installs'], 'total' => $wp['installs']]),
                        'detail' => __('WordPress'), 'url' => route('admin.wordpress.index', ['filter' => 'outdated']), 'action' => __('Update')];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return array_slice($items, 0, 6);
    }
}
